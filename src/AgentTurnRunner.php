<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\Exception\TurnTimeoutException;

/**
 * Runs one turn of a Managed Agents conversation.
 *
 * The conversation state lives on Anthropic's side (a session), so only the new
 * user message is sent and the reply is collected by polling the session events -
 * a worker cannot hold an SSE stream. Custom tool calls are executed locally
 * through the {@see ToolExecutor} and their results are sent back until the
 * session becomes idle.
 *
 * The runner is deliberately persistence-free: it takes a conversation, returns a
 * {@see TurnResult} and only touches the conversation's session id. Storing
 * messages, tokens and ownership is the caller's job.
 */
class AgentTurnRunner
{
	private const EVENTS_PAGE_SIZE = 100;

	/** @var callable(): AgentResolution */
	private $agentResolver;

	/**
	 * @param callable(): AgentResolution $agentResolver resolves the agent and
	 *        environment for a new session; see {@see AgentProvisioner::resolver()}
	 */
	public function __construct(
		private readonly ManagedAgentsClient $client,
		private readonly ToolExecutor $toolExecutor,
		callable $agentResolver,
		private readonly AiChatOptions $options = new AiChatOptions(),
	) {
		$this->agentResolver = $agentResolver;
	}

	/**
	 * Sends the user message and waits for the reply.
	 *
	 * When an EXISTING session refuses the message (expired, deleted, or stuck in
	 * requires_action after an interrupted turn), one retry is made with a fresh
	 * session. A timeout is never retried this way - the message has already been
	 * delivered and a new session would both duplicate it and throw away the
	 * server-side context.
	 *
	 * @param callable(?string): void $persistSessionId called whenever the session id
	 *        of the conversation changes, so the caller can flush it immediately
	 * @param list<Attachment> $attachments files already uploaded to the Files API,
	 *        sent as image/document content blocks of the same user.message
	 * @param (callable(): string)|null $history the earlier transcript of the
	 *        conversation; asked only when a NEW session starts for it (a switched
	 *        model, an expired session) and sent ahead of the message, so the session
	 *        does not start without context
	 * @param string|null $model the model a new session runs on (an override of the
	 *        agent); null = the model of the agent
	 * @param string|null $effort thinking effort of a new session; null = the model default
	 * @param TurnMonitor|null $monitor watches the progress and may stop the turn; a
	 *        stopped turn returns what it has with {@see TurnResult::$interrupted}
	 * @throws AiChatException|TurnTimeoutException
	 */
	public function run(
		ConversationInterface $conversation,
		string $userMessage,
		callable $persistSessionId,
		array $attachments = [],
		?callable $history = null,
		?string $model = null,
		?string $effort = null,
		?TurnMonitor $monitor = null,
	): TurnResult {
		// Stopped before it started (the worker picked the message up late) - the
		// message is not delivered at all.
		if ($monitor?->isCancelled()) {
			return new TurnResult('', [], 0, 0, true);
		}

		$reusedExisting = ($conversation->getSessionId() ?? '') !== '';
		$sessionId = $this->ensureSession($conversation, $persistSessionId, $model, $effort);

		try {
			return $this->runSessionTurn($sessionId, $userMessage, $attachments, !$reusedExisting && $history !== null ? $history() : '', $monitor);
		} catch (AiChatException $e) {
			if ($e instanceof TurnTimeoutException || !$reusedExisting) {
				throw $e;
			}

			$conversation->setSessionId(null);
			$persistSessionId(null);

			$sessionId = $this->ensureSession($conversation, $persistSessionId, $model, $effort);

			return $this->runSessionTurn($sessionId, $userMessage, $attachments, $history !== null ? $history() : '', $monitor);
		}
	}

	/**
	 * @param callable(?string): void $persistSessionId
	 * @throws AiChatException
	 */
	private function ensureSession(ConversationInterface $conversation, callable $persistSessionId, ?string $model, ?string $effort): string
	{
		$existing = $conversation->getSessionId();
		if ($existing !== null && $existing !== '') {
			return $existing;
		}

		$resolution = ($this->agentResolver)();

		$session = $this->client->createSession(
			$resolution->agentId,
			$resolution->environmentId,
			$conversation->getTitle() !== '' ? $conversation->getTitle() : null,
			// The chosen model overrides the one of the agent.
			$model !== null && $model !== '' ? ['id' => $model] + ($effort !== null && $effort !== '' ? ['effort' => $effort] : []) : null,
		);

		$sessionId = (string) ($session['id'] ?? '');
		if ($sessionId === '') {
			throw new AiChatException('Managed Agents: failed to create a session (no id in the response).');
		}

		$conversation->setSessionId($sessionId);
		$persistSessionId($sessionId);

		return $sessionId;
	}

	/**
	 * @param list<Attachment> $attachments
	 * @throws AiChatException
	 */
	private function runSessionTurn(string $sessionId, string $userMessage, array $attachments, string $history, ?TurnMonitor $monitor): TurnResult
	{
		// Events present before sending are history and must be ignored.
		$seen = $this->snapshotEventIds($sessionId);

		// Attachments go first so the model reads the files before the question.
		$content = array_map(
			static fn(Attachment $attachment): array => $attachment->toContentBlock(),
			$attachments,
		);
		// A message may consist of attachments only; an empty text block is refused.
		if ($userMessage !== '' || !$content) {
			$content[] = ['type' => 'text', 'text' => $userMessage];
		}
		if ($history !== '') {
			array_unshift($content, ['type' => 'text', 'text' => $history]);
		}

		$this->client->sendSessionEvents($sessionId, [[
			'type' => 'user.message',
			'content' => $content,
		]]);

		return $this->pollUntilIdle($sessionId, $seen, $monitor);
	}

	/**
	 * The agent loop over event polling: read new events, execute custom tools and
	 * send their results back, until the session reaches a terminal idle state.
	 *
	 * The turn has no fixed length - it lasts while the session keeps producing
	 * events or reports itself running ({@see AiChatOptions}).
	 *
	 * Subagents (a multiagent session) run in threads of their own. The session
	 * stream carries their lifecycle, the task they got and the report they sent
	 * back, and their custom tool calls cross-posted with `session_thread_id` - the
	 * id is echoed in the tool result. Their work goes to the tool data (see
	 * {@see TurnResult::$toolData}); the reply text is the main thread's only.
	 *
	 * @param array<string, true> $seen ids of events present before the message was sent
	 * @throws AiChatException
	 */
	private function pollUntilIdle(string $sessionId, array $seen, ?TurnMonitor $monitor): TurnResult
	{
		$texts = [];
		$toolData = [];
		$tokensInput = 0;
		$tokensOutput = 0;
		/** @var array<string, int> $toolCalls per thread; '' = the main one */
		$toolCalls = [];
		/** @var array<string, array{id: string, name: string, status: string, task: string, report: string, tool_calls: int, delegated: bool, entry: int|null}> $threads */
		$threads = [];
		$idle = false;
		$terminated = false;
		$started = $lastActivity = microtime(true);
		$interruptedAt = null;

		while (true) {
			usleep($this->options->pollIntervalMicroseconds);

			if ($interruptedAt === null && $monitor?->isCancelled()) {
				$this->interrupt($sessionId);
				$interruptedAt = microtime(true);
			}

			$events = $this->fetchNewEvents($sessionId, $seen);
			$toolResults = [];

			foreach ($events as $event) {
				$id = (string) $event['id'];
				$thread = (string) ($event['session_thread_id'] ?? '');
				// Only a thread the coordinator delegated to is a subagent - events of
				// the main thread may carry its id too.
				$child = $thread !== '' && ($threads[$thread]['delegated'] ?? false) ? $thread : null;

				switch ($event['type'] ?? '') {
					case 'agent.message':
						if ($child !== null) {
							break;
						}
						foreach ($event['content'] ?? [] as $block) {
							if (($block['type'] ?? '') === 'text') {
								$texts[] = $block['text'];
							}
						}
						break;

					case 'agent.custom_tool_use':
						// The interrupt closes pending tool calls itself.
						if ($interruptedAt !== null) {
							break;
						}

						// A timeout is deliberately NOT retried with a new session, so a
						// runaway agent cannot cause the user message to be re-sent.
						$toolCalls[$thread] = ($toolCalls[$thread] ?? 0) + 1;
						if ($toolCalls[$thread] > $this->options->maxToolCalls) {
							$this->interrupt($sessionId);
							throw TurnTimeoutException::translatable(
								'The assistant made too many tool calls in a single turn and was stopped. Please refine your question.',
								'aiChat.error.tooManyToolCalls',
							);
						}

						$toolResult = $this->toolExecutor->handleToolCall(
							(string) ($event['name'] ?? ''),
							(array) ($event['input'] ?? []),
						);

						$toolData[] = [
							'tool_name' => $event['name'] ?? '',
							'tool_input' => (array) ($event['input'] ?? []),
							'tool_result' => $toolResult,
						] + ($child !== null ? ['thread' => $child] : []);

						if ($child !== null) {
							$threads[$child]['tool_calls']++;
						}

						$toolResults[] = [
							'type' => 'user.custom_tool_result',
							'custom_tool_use_id' => $id,
							'content' => [['type' => 'text', 'text' => json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
							'is_error' => isset($toolResult['error']),
						] + ($thread !== '' ? ['session_thread_id' => $thread] : []);
						break;

					case 'span.model_request_end':
						$usage = $event['model_usage'] ?? [];
						// Output tokens ARE summed - the total output of the turn,
						// subagents included.
						$tokensOutput += (int) ($usage['output_tokens'] ?? 0);
						if ($child !== null) {
							break;
						}
						// The window usage equals the FULL input of the last request. With
						// prompt caching the API reports only the uncached part in
						// input_tokens; the rest sits in the cache counters, so all three
						// must be summed - otherwise the gauge shows a few tokens instead
						// of the real size. The LAST request wins (no summing across
						// requests): each one resends the whole growing context.
						$tokensInput = (int) ($usage['input_tokens'] ?? 0)
							+ (int) ($usage['cache_read_input_tokens'] ?? 0)
							+ (int) ($usage['cache_creation_input_tokens'] ?? 0);
						break;

					case 'session.thread_created':
						self::registerThread($threads, $thread, (string) ($event['agent_name'] ?? ''));
						break;

					case 'agent.thread_message_sent':
						// The coordinator hands a task (or a follow-up) to a subagent.
						$to = (string) ($event['to_session_thread_id'] ?? '');
						if ($to === '') {
							break;
						}
						self::registerThread($threads, $to, (string) ($event['to_agent_name'] ?? ''));
						$threads[$to]['delegated'] = true;
						$threads[$to]['status'] = 'running';
						$threads[$to]['task'] = ltrim($threads[$to]['task'] . "\n\n" . self::contentText($event['content'] ?? ''));
						if ($threads[$to]['entry'] === null) {
							// A placeholder keeps the subagent in order among the tool calls.
							$threads[$to]['entry'] = count($toolData);
							$toolData[] = [];
						}
						break;

					case 'agent.thread_message_received':
						// A subagent reports back to the coordinator.
						$from = (string) ($event['from_session_thread_id'] ?? '');
						if (isset($threads[$from])) {
							$threads[$from]['report'] = ltrim($threads[$from]['report'] . "\n\n" . self::contentText($event['content'] ?? ''));
						}
						break;

					case 'session.thread_status_running':
					case 'session.thread_status_rescheduled':
					case 'session.thread_status_idle':
					case 'session.thread_status_terminated':
						if (isset($threads[$thread])) {
							$threads[$thread]['status'] = match ($event['type']) {
								'session.thread_status_running' => 'running',
								'session.thread_status_rescheduled' => 'rescheduling',
								'session.thread_status_idle' => 'idle',
								default => 'terminated',
							};
						}
						break;

					case 'session.status_running':
						$idle = false;
						break;

					case 'session.status_idle':
						// requires_action = waiting for our tool result; anything else is terminal.
						$idle = (string) ($event['stop_reason']['type'] ?? '') !== 'requires_action';
						break;

					case 'session.status_terminated':
						$terminated = true;
						break;
				}
			}

			if ($toolResults) {
				$this->client->sendSessionEvents($sessionId, $toolResults);
			}

			$now = microtime(true);
			if ($events) {
				$lastActivity = $now;
				$monitor?->onProgress(self::progress($threads, $toolCalls));
			}

			// A subagent still at work keeps the turn open even when the session
			// reported idle in between.
			$subagentRunning = (bool) array_filter(
				$threads,
				static fn(array $t): bool => $t['delegated'] && in_array($t['status'], ['running', 'rescheduling'], true),
			);
			if ($terminated || ($idle && !$subagentRunning)) {
				break;
			}

			// Cancelled, but the session did not confirm it stopped - give up waiting.
			if ($interruptedAt !== null && $now - $interruptedAt >= $this->options->interruptGraceSeconds) {
				break;
			}

			if ($now - $started >= $this->options->maxTurnSeconds) {
				$this->interrupt($sessionId);
				throw TurnTimeoutException::translatable(
					'The assistant did not finish responding in time. Please send your message again.',
					'aiChat.error.turnTimeout',
				);
			}

			if ($interruptedAt === null && $now - $lastActivity >= $this->options->idleTimeoutSeconds) {
				// A quiet stream does not mean a lost turn - a long model request emits
				// nothing until it ends. Only a session that no longer runs is lost.
				$status = (string) ($this->client->retrieveSession($sessionId)['status'] ?? '');
				if ($status !== 'running' && $status !== 'rescheduling') {
					$this->interrupt($sessionId);
					throw TurnTimeoutException::translatable(
						'The assistant did not finish responding in time. Please send your message again.',
						'aiChat.error.turnTimeout',
					);
				}
				$lastActivity = $now;
				$monitor?->onProgress(self::progress($threads, $toolCalls));
			}
		}

		foreach ($threads as $t) {
			if ($t['entry'] !== null) {
				$toolData[$t['entry']] = [
					'tool_name' => 'subagent',
					'tool_input' => ['thread' => $t['id'], 'name' => $t['name'], 'task' => $t['task']],
					'tool_result' => ['status' => $t['status'], 'report' => $t['report']],
				];
			}
		}

		return new TurnResult(implode("\n", $texts), $toolData, $tokensInput, $tokensOutput, $interruptedAt !== null);
	}

	/**
	 * Events added since the last call, oldest first. The newest page is read first
	 * and older pages only until an already seen event shows up - so the cost does
	 * not grow with the length of the session (reading it from the start would also
	 * stop seeing new events once it outgrows one page).
	 *
	 * @param array<string, true> $seen updated with the returned events
	 * @return list<array<string, mixed>>
	 */
	private function fetchNewEvents(string $sessionId, array &$seen): array
	{
		$new = [];
		$page = null;

		do {
			$response = $this->client->listSessionEvents($sessionId, self::EVENTS_PAGE_SIZE, $page, 'desc');
			foreach ($response['data'] ?? [] as $event) {
				$id = (string) ($event['id'] ?? '');
				// Some events (user.interrupt) may come without an id; none of them matters here.
				if ($id === '') {
					continue;
				}
				if (isset($seen[$id])) {
					break 2;
				}
				$new[] = $event;
			}
			$page = $response['next_page'] ?? null;
		} while (is_string($page) && $page !== '');

		$new = array_reverse($new);
		foreach ($new as $event) {
			$seen[(string) $event['id']] = true;
		}

		return $new;
	}

	/**
	 * @return array<string, true> ids of the newest events in the session - enough
	 *         for {@see fetchNewEvents()} to tell where the new ones start
	 */
	private function snapshotEventIds(string $sessionId): array
	{
		$ids = [];
		foreach ($this->client->listSessionEvents($sessionId, self::EVENTS_PAGE_SIZE, null, 'desc')['data'] ?? [] as $event) {
			if (($event['id'] ?? '') !== '') {
				$ids[(string) $event['id']] = true;
			}
		}

		return $ids;
	}

	/** Stops every thread of the session; best effort - the turn ends either way. */
	private function interrupt(string $sessionId): void
	{
		try {
			$this->client->sendSessionEvents($sessionId, [['type' => 'user.interrupt']]);
		} catch (\Throwable) {
		}
	}

	/**
	 * @param array<string, array{id: string, name: string, status: string, task: string, report: string, tool_calls: int, delegated: bool, entry: int|null}> $threads
	 */
	private static function registerThread(array &$threads, string $id, string $name): void
	{
		if ($id === '') {
			return;
		}

		$threads[$id] ??= ['id' => $id, 'name' => $name, 'status' => 'running', 'task' => '', 'report' => '', 'tool_calls' => 0, 'delegated' => false, 'entry' => null];
		if ($threads[$id]['name'] === '') {
			$threads[$id]['name'] = $name;
		}
	}

	/**
	 * @param array<string, array{id: string, name: string, status: string, task: string, report: string, tool_calls: int, delegated: bool, entry: int|null}> $threads
	 * @param array<string, int> $toolCalls
	 * @return array{tool_calls: int, threads: list<array{id: string, name: string, status: string, task: string, tool_calls: int}>}
	 */
	private static function progress(array $threads, array $toolCalls): array
	{
		$delegated = array_filter($threads, static fn(array $t): bool => $t['delegated']);

		return [
			'tool_calls' => array_sum(array_diff_key($toolCalls, $delegated)),
			'threads' => array_values(array_map(
				static fn(array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'status' => $t['status'], 'task' => $t['task'], 'tool_calls' => $t['tool_calls']],
				$delegated,
			)),
		];
	}

	/** Text of a message content - a string or a list of content blocks. */
	private static function contentText(mixed $content): string
	{
		if (is_string($content)) {
			return $content;
		}

		$texts = [];
		foreach (is_array($content) ? $content : [] as $block) {
			if (is_array($block) && ($block['type'] ?? '') === 'text') {
				$texts[] = (string) ($block['text'] ?? '');
			}
		}

		return implode("\n", $texts);
	}
}
