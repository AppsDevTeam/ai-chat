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
	 * @throws AiChatException|TurnTimeoutException
	 */
	public function run(ConversationInterface $conversation, string $userMessage, callable $persistSessionId): TurnResult
	{
		$reusedExisting = ($conversation->getSessionId() ?? '') !== '';
		$sessionId = $this->ensureSession($conversation, $persistSessionId);

		try {
			return $this->runSessionTurn($sessionId, $userMessage);
		} catch (AiChatException $e) {
			if ($e instanceof TurnTimeoutException || !$reusedExisting) {
				throw $e;
			}

			$conversation->setSessionId(null);
			$persistSessionId(null);

			$sessionId = $this->ensureSession($conversation, $persistSessionId);

			return $this->runSessionTurn($sessionId, $userMessage);
		}
	}

	/**
	 * @param callable(?string): void $persistSessionId
	 * @throws AiChatException
	 */
	private function ensureSession(ConversationInterface $conversation, callable $persistSessionId): string
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
	 * @throws AiChatException
	 */
	private function runSessionTurn(string $sessionId, string $userMessage): TurnResult
	{
		// Events present before sending are history and must be ignored.
		$seen = $this->collectEventIds($sessionId);

		$this->client->sendSessionEvents($sessionId, [[
			'type' => 'user.message',
			'content' => [['type' => 'text', 'text' => $userMessage]],
		]]);

		return $this->pollUntilIdle($sessionId, $seen);
	}

	/**
	 * The agent loop over event polling: read new events, execute custom tools and
	 * send their results back, until the session reaches a terminal idle state.
	 *
	 * @param array<string, true> $seen ids of events present before the message was sent
	 * @throws AiChatException
	 */
	private function pollUntilIdle(string $sessionId, array $seen): TurnResult
	{
		$texts = [];
		$toolData = [];
		$tokensInput = 0;
		$tokensOutput = 0;
		$toolCalls = 0;
		$completed = false;

		for ($poll = 0; $poll < $this->options->maxPolls; $poll++) {
			usleep($this->options->pollIntervalMicroseconds);

			$events = $this->client->listSessionEvents($sessionId)['data'] ?? [];
			$done = false;
			$toolResults = [];

			foreach ($events as $event) {
				$id = (string) ($event['id'] ?? '');
				if ($id === '' || isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;

				switch ($event['type'] ?? '') {
					case 'agent.message':
						foreach ($event['content'] ?? [] as $block) {
							if (($block['type'] ?? '') === 'text') {
								$texts[] = $block['text'];
							}
						}
						break;

					case 'agent.custom_tool_use':
						// A timeout is deliberately NOT retried with a new session, so a
						// runaway agent cannot cause the user message to be re-sent.
						if (++$toolCalls > $this->options->maxToolCalls) {
							throw new TurnTimeoutException('The assistant made too many tool calls in a single turn and was stopped. Please refine your question.');
						}

						$toolResult = $this->toolExecutor->handleToolCall(
							(string) ($event['name'] ?? ''),
							(array) ($event['input'] ?? []),
						);

						$toolData[] = [
							'tool_name' => $event['name'] ?? '',
							'tool_input' => (array) ($event['input'] ?? []),
							'tool_result' => $toolResult,
						];

						$toolResults[] = [
							'type' => 'user.custom_tool_result',
							'custom_tool_use_id' => $id,
							'content' => [['type' => 'text', 'text' => json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
							'is_error' => isset($toolResult['error']),
						];
						break;

					case 'span.model_request_end':
						$usage = $event['model_usage'] ?? [];
						// The window usage equals the FULL input of the last request. With
						// prompt caching the API reports only the uncached part in
						// input_tokens; the rest sits in the cache counters, so all three
						// must be summed - otherwise the gauge shows a few tokens instead
						// of the real size. The LAST request wins (no summing across
						// requests): each one resends the whole growing context.
						$tokensInput = (int) ($usage['input_tokens'] ?? 0)
							+ (int) ($usage['cache_read_input_tokens'] ?? 0)
							+ (int) ($usage['cache_creation_input_tokens'] ?? 0);
						// Output tokens ARE summed - the total output of the turn.
						$tokensOutput += (int) ($usage['output_tokens'] ?? 0);
						break;

					case 'session.status_idle':
						// requires_action = waiting for our tool result; anything else is terminal.
						if ((string) ($event['stop_reason']['type'] ?? '') !== 'requires_action') {
							$done = true;
						}
						break;

					case 'session.status_terminated':
						$done = true;
						break;
				}
			}

			if ($toolResults) {
				$this->client->sendSessionEvents($sessionId, $toolResults);
			}

			if ($done) {
				$completed = true;
				break;
			}
		}

		// Running out of polls without a terminal state must NOT pass as a successful
		// (empty or partial) reply - the session is still mid-turn.
		if (!$completed) {
			throw new TurnTimeoutException('The assistant did not finish responding in time. Please send your message again.');
		}

		return new TurnResult(implode("\n", $texts), $toolData, $tokensInput, $tokensOutput);
	}

	/**
	 * @return array<string, true> ids of all events currently in the session
	 */
	private function collectEventIds(string $sessionId): array
	{
		$ids = [];
		foreach ($this->client->listSessionEvents($sessionId)['data'] ?? [] as $event) {
			if (isset($event['id'])) {
				$ids[(string) $event['id']] = true;
			}
		}

		return $ids;
	}
}
