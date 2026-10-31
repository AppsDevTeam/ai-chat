<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Dispatch\MessageDispatcher;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\Exception\TurnTimeoutException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The coordinator of the whole life of a message, over your Doctrine entities.
 *
 * The flow splits in two, because an agent turn takes minutes and must never run
 * inside a web request:
 *
 *   web request:  enqueueMessage()       - rate limit, upload attachments, store
 *                                          the user message, hand off to background
 *   worker:       processQueuedMessage() - run the turn, redact, store the reply
 *
 * Conversations belong to an owner - an association (or a plain column) of the
 * conversation entity named by {@see ChatOptions::$ownerField}. Everything that
 * takes an owner scopes the data to it, so a foreign conversation reads as "not
 * found". Who may look at whose conversations is the caller's decision.
 *
 * With {@see ChatOptions::$senderField} other users may write into a conversation
 * too: every user message remembers its sender and the rate limit counts per sender.
 */
class ChatService
{
	private ?AgentTurnRunner $runner = null;

	/** @var (callable(string, array<string, scalar>, string): string)|null */
	private $translator = null;

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly ManagedAgentsClient $client,
		private readonly ToolExecutor $toolExecutor,
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly AgentProvisioner $provisioner,
		private readonly MessageDispatcher $dispatcher,
		private readonly PiiResponseFilter $piiFilter,
		private readonly PiiValueCollector $piiValueCollector,
		private readonly ChatOptions $options,
		private readonly AiChatOptions $turnOptions = new AiChatOptions(),
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * Localizes the messages of {@see AiChatException::translatable()} failures -
	 * both those returned to the browser and the error replies stored by the worker.
	 *
	 * @param callable(string $key, array<string, scalar> $params, string $default): string $translator
	 */
	public function setTranslator(callable $translator): static
	{
		$this->translator = $translator;
		return $this;
	}

	public function getOptions(): ChatOptions
	{
		return $this->options;
	}

	// -- Conversations ---------------------------------------------------------

	public function createConversation(object|int|string $owner): ConversationInterface
	{
		$class = $this->options->conversationClass;
		$conversation = new $class();
		$this->em->getClassMetadata($class)->setFieldValue($conversation, $this->options->ownerField, $owner);

		$now = new \DateTimeImmutable();
		$conversation->setCreated($now);
		$conversation->setUpdated($now);

		$this->em->persist($conversation);
		$this->em->flush();

		return $conversation;
	}

	/**
	 * @return list<ConversationInterface> newest activity first
	 */
	public function getConversations(object|int|string $owner): array
	{
		return array_values($this->em->getRepository($this->options->conversationClass)->findBy(
			[$this->options->ownerField => $owner, 'deleted' => false],
			['updated' => 'DESC'],
		));
	}

	/** A conversation of the owner; deleted and foreign ones read as null. */
	public function getConversation(int|string $conversationId, object|int|string $owner): ?ConversationInterface
	{
		$class = $this->options->conversationClass;

		/** @var ConversationInterface|null */
		return $this->em->getRepository($class)->findOneBy([
			$this->em->getClassMetadata($class)->getSingleIdentifierFieldName() => $conversationId,
			$this->options->ownerField => $owner,
			'deleted' => false,
		]);
	}

	/** A (not deleted) conversation regardless of its owner - for privileged views. */
	public function findConversation(int|string $conversationId): ?ConversationInterface
	{
		$conversation = $this->em->find($this->options->conversationClass, $conversationId);

		return $conversation instanceof ConversationInterface && !$conversation->isDeleted() ? $conversation : null;
	}

	public function getOwner(ConversationInterface $conversation): mixed
	{
		return $this->em->getClassMetadata($this->options->conversationClass)
			->getFieldValue($conversation, $this->options->ownerField);
	}

	/** Who wrote the user message; null without {@see ChatOptions::$senderField} or for AI replies. */
	public function getSender(MessageInterface $message): mixed
	{
		if ($this->options->senderField === null) {
			return null;
		}

		return $this->em->getClassMetadata($this->options->messageClass)
			->getFieldValue($message, $this->options->senderField);
	}

	/**
	 * Do both values stand for the same user? Entities compare by their identifier,
	 * so an entity equals its proxy or its plain id.
	 */
	public function isSameUser(mixed $a, mixed $b): bool
	{
		if ($a === null || $b === null) {
			return false;
		}

		return $a === $b || $this->userKey($a) === $this->userKey($b);
	}

	/**
	 * The user's feedback on the conversation; a null rating clears it together with
	 * the comment. Rating again overwrites the previous one.
	 *
	 * @throws AiChatException
	 */
	public function rateConversation(ConversationInterface $conversation, ?int $rating, ?string $comment = null): void
	{
		if ($rating !== null && $rating !== ConversationInterface::RATING_UP && $rating !== ConversationInterface::RATING_DOWN) {
			throw AiChatException::translatable('Invalid rating.', 'aiChat.error.invalidRating');
		}

		$comment = $comment !== null ? trim($comment) : '';

		$conversation->setRating($rating);
		$conversation->setRatingComment($rating !== null && $comment !== '' ? mb_substr($comment, 0, $this->options->ratingCommentMaxLength) : null);
		$conversation->setRated($rating !== null ? new \DateTimeImmutable() : null);
		$this->em->flush();
	}

	/** Soft delete - the transcript stays for audits and statistics. */
	public function deleteConversation(ConversationInterface $conversation): void
	{
		$conversation->setDeleted(true);
		$this->em->flush();
	}

	/**
	 * The model and effort the conversation runs on - those of its latest message;
	 * null for a conversation without messages. Every message stores the choice it
	 * was sent or answered with, the conversation itself keeps none. The model can
	 * be switched between messages; a session cannot switch models, so the next turn
	 * starts a new one, seeded with the transcript ({@see buildHistory()}).
	 *
	 * @return array{model: string|null, effort: string|null}|null
	 */
	public function getModelChoice(ConversationInterface $conversation): ?array
	{
		if ($conversation->getId() === null) {
			return null;
		}

		$row = $this->em->createQueryBuilder()
			->select('m.model, m.effort')
			->from($this->options->messageClass, 'm')
			->where('m.' . $this->options->conversationField . ' = :conversation')
			->setParameter('conversation', $conversation)
			->orderBy('m.' . $this->messageIdField(), 'DESC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();

		return $row !== null ? ['model' => $row['model'], 'effort' => $row['effort']] : null;
	}

	// -- Messages --------------------------------------------------------------

	/**
	 * @return list<MessageInterface>
	 */
	public function getMessages(ConversationInterface $conversation): array
	{
		return array_values($this->em->getRepository($this->options->messageClass)->findBy(
			[$this->options->conversationField => $conversation],
			['created' => 'ASC', $this->messageIdField() => 'ASC'],
		));
	}

	/**
	 * Messages newer than $afterId - the frontend polls with the id of the message
	 * it just sent and stops once the AI reply (or an error message) arrives.
	 *
	 * @return list<MessageInterface>
	 */
	public function getMessagesAfter(ConversationInterface $conversation, int|string $afterId): array
	{
		$idField = $this->messageIdField();

		return array_values($this->em->createQueryBuilder()
			->select('m')
			->from($this->options->messageClass, 'm')
			->where('m.' . $this->options->conversationField . ' = :conversation')
			->andWhere('m.' . $idField . ' > :afterId')
			->setParameter('conversation', $conversation)
			->setParameter('afterId', $afterId)
			->orderBy('m.' . $idField, 'ASC')
			->getQuery()
			->getResult());
	}

	public function getMessage(ConversationInterface $conversation, int|string $messageId): ?MessageInterface
	{
		/** @var MessageInterface|null */
		return $this->em->getRepository($this->options->messageClass)->findOneBy([
			$this->messageIdField() => $messageId,
			$this->options->conversationField => $conversation,
		]);
	}

	/**
	 * Context-window usage for the UI gauge. Managed Agents resends the whole
	 * session context with every request, so the tokensInput of the latest reply
	 * equals the current window usage.
	 *
	 * The window is the one of the conversation's model ({@see ModelSelection}), or
	 * {@see ChatOptions::$contextWindowTokens} when it has none.
	 *
	 * With model selection it also carries the conversation's model and effort and
	 * `historyTokens` - an estimate of the context a new session would start with
	 * (switching the model in the widget drops the session, the transcript follows):
	 * the input of the first reply (system prompt, tools) plus the transcript. The
	 * widget measures the gauge against the selected model with it.
	 *
	 * @return array{used: int, window: int, percent: int, model?: ?string, effort?: ?string, historyTokens?: int}
	 */
	public function getContextUsage(ConversationInterface $conversation): array
	{
		$row = $this->em->createQueryBuilder()
			->select('m.tokensInput')
			->from($this->options->messageClass, 'm')
			->where('m.' . $this->options->conversationField . ' = :conversation')
			->andWhere('m.tokensInput IS NOT NULL')
			->setParameter('conversation', $conversation)
			->orderBy('m.' . $this->messageIdField(), 'DESC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();

		$used = (int) ($row['tokensInput'] ?? 0);
		$choice = $this->getModelChoice($conversation);
		$window = $this->options->modelSelection->getContextWindow($choice['model'] ?? null, $this->options->contextWindowTokens);

		$usage = [
			'used' => $used,
			'window' => $window,
			'percent' => $window > 0 ? min(100, (int) round($used / $window * 100)) : 0,
		];

		if ($used > 0 && $this->options->modelSelection->isEnabled()) {
			$usage['model'] = $choice['model'] ?? null;
			$usage['effort'] = $choice['effort'] ?? null;
			$usage['historyTokens'] = $this->estimateNewSessionTokens($conversation);
		}

		return $usage;
	}

	/** Rough token count of a new session: first reply's input + transcript (~3 chars per token). */
	private function estimateNewSessionTokens(ConversationInterface $conversation): int
	{
		$row = $this->em->createQueryBuilder()
			->select('m.tokensInput')
			->from($this->options->messageClass, 'm')
			->where('m.' . $this->options->conversationField . ' = :conversation')
			->andWhere('m.tokensInput IS NOT NULL')
			->setParameter('conversation', $conversation)
			->orderBy('m.' . $this->messageIdField(), 'ASC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();

		return (int) ($row['tokensInput'] ?? 0) + (int) ceil(mb_strlen($this->buildHistory($conversation)) / 3);
	}

	/**
	 * The transcript before the message being processed, for a new session of an
	 * existing conversation (a switched model, an expired session) - otherwise the
	 * session would start without any context. Tool calls are summarized (SQL, the
	 * rendered tables and charts); beyond {@see ChatOptions::$historyMaxChars} the
	 * oldest messages are left out. Empty for a conversation without history.
	 */
	public function buildHistory(ConversationInterface $conversation): string
	{
		$messages = $this->getMessages($conversation);

		// The trailing user message is the one being processed - it is sent as itself.
		$last = end($messages);
		if ($last !== false && $last->getAuthor() === MessageInterface::AUTHOR_USER) {
			array_pop($messages);
		}

		$parts = [];
		foreach ($messages as $message) {
			$part = $message->isError() ? '' : $this->formatHistoryMessage($message);
			if ($part !== '') {
				$parts[] = $part;
			}
		}

		$kept = [];
		$length = 0;
		foreach (array_reverse($parts) as $part) {
			$length += mb_strlen($part) + 2;
			if ($length > $this->options->historyMaxChars) {
				if ($kept === []) {
					$kept[] = mb_substr($part, 0, $this->options->historyMaxChars) . ' [...]';
				}
				break;
			}
			$kept[] = $part;
		}

		if ($kept === []) {
			return '';
		}

		$omitted = count($parts) - count($kept);

		return "This conversation continues from an earlier session, possibly on another model. Its transcript follows for context."
			. " Results of the earlier tool calls are available only as shown - run the queries again when you need the data.\n\n"
			. "<conversation_history>\n"
			. ($omitted > 0 ? sprintf("[%d older messages left out]\n\n", $omitted) : '')
			. implode("\n\n", array_reverse($kept))
			. "\n</conversation_history>";
	}

	private function formatHistoryMessage(MessageInterface $message): string
	{
		if ($message->getAuthor() === MessageInterface::AUTHOR_USER) {
			$files = array_map(static fn(array $a): string => (string) ($a['filename'] ?? ''), $message->getAttachments() ?? []);

			return 'User: ' . $message->getContent() . ($files ? ' [attached files: ' . implode(', ', $files) . ']' : '');
		}

		$lines = [];
		foreach ($message->getToolData() ?? [] as $entry) {
			$name = (string) ($entry['tool_name'] ?? '');
			$input = (array) ($entry['tool_input'] ?? []);
			$result = ToolHandler::normalizeRenderResult((array) ($entry['tool_result'] ?? []));

			$line = match ($name) {
				'execute_sql', 'export_csv' => is_string($input['sql'] ?? null) ? sprintf('[%s] %s', $name, $input['sql']) : '',
				'render_table' => sprintf("[render_table] %s\n%s", $result['title'] ?? '', self::formatHistoryTable($result['headers'] ?? [], $result['rows'] ?? [])),
				'render_chart' => sprintf('[render_chart] %s: %s', $result['title'] ?? '', json_encode([
					'labels' => $result['labels'] ?? [],
					'datasets' => $result['datasets'] ?? [],
				], JSON_UNESCAPED_UNICODE)),
				'subagent' => sprintf("[subagent %s] task: %s\nreport: %s", $input['name'] ?? '', $input['task'] ?? '', $entry['tool_result']['report'] ?? ''),
				default => '',
			};
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($message->getContent() !== '') {
			$lines[] = 'Assistant: ' . $message->getContent();
		}
		if ($message->isInterrupted()) {
			$lines[] = '[the user stopped this reply]';
		}

		return implode("\n", $lines);
	}

	/**
	 * @param list<mixed> $headers
	 * @param list<list<mixed>> $rows
	 */
	private static function formatHistoryTable(array $headers, array $rows, int $maxRows = 50): string
	{
		$line = static fn(array $cells): string => implode(' | ', array_map(static fn(mixed $cell): string => (string) $cell, $cells));

		$lines = [$line($headers)];
		foreach (array_slice($rows, 0, $maxRows) as $row) {
			$lines[] = $line($row);
		}
		if (count($rows) > $maxRows) {
			$lines[] = sprintf('[... %d more rows]', count($rows) - $maxRows);
		}

		return implode("\n", $lines);
	}

	// -- The message flow ------------------------------------------------------

	/**
	 * Web request part: cheap and fast.
	 *
	 * Attachments are pushed to the Files API right away (seconds, acceptable in a
	 * web request) and only the returned file ids travel through the queue - the
	 * worker replays them as content blocks of the user message.
	 *
	 * The model and effort may change with any message; a change starts a new session
	 * for the next turn (a session cannot switch models), which gets the earlier
	 * transcript. Null keeps the model of the conversation, for a new one it means the
	 * default of {@see ModelSelection}. The message stores the model it was sent with.
	 *
	 * $sender is who writes the message (stored with {@see ChatOptions::$senderField});
	 * null = the owner of the conversation.
	 *
	 * @param list<AttachmentUpload> $uploads
	 * @throws AiChatException with a message for the user (rate limit, attachments, API,
	 *         a model or effort that is not offered)
	 */
	public function enqueueMessage(
		ConversationInterface $conversation,
		string $userMessage,
		array $uploads = [],
		?string $model = null,
		?string $effort = null,
		object|int|string|null $sender = null,
	): MessageInterface {
		$userMessage = trim($userMessage);
		if ($userMessage === '' && !$uploads) {
			throw AiChatException::translatable('The message cannot be empty.', 'aiChat.error.emptyMessage');
		}

		$this->checkPendingTurn($conversation);

		$sender = $this->options->senderField !== null ? ($sender ?? $this->getOwner($conversation)) : null;
		$this->checkRateLimit($sender ?? $this->getOwner($conversation));

		// Validated before the uploads, stored only once nothing can fail anymore.
		$current = $this->getModelChoice($conversation);
		$modelChoice = $current === null || ($model ?? '') !== '' || ($effort ?? '') !== ''
			? $this->options->modelSelection->resolve($model, $effort)
			: $current;

		$attachments = $this->uploadAttachments($uploads);

		if ($current !== null && ($modelChoice['model'] !== $current['model'] || $modelChoice['effort'] !== $current['effort'])) {
			// The session runs on the old model; the next turn starts a new one.
			$conversation->setSessionId(null);
		}

		// The transcript is kept on our side too - history does not depend on the session.
		$message = $this->addMessage($conversation, $userMessage, MessageInterface::AUTHOR_USER, attachments: $attachments ?: null, modelChoice: $modelChoice);
		if ($sender !== null) {
			$this->em->getClassMetadata($this->options->messageClass)->setFieldValue($message, $this->options->senderField, $sender);
		}

		if ($conversation->getTitle() === '') {
			$conversation->setTitle($this->createTitle($userMessage !== '' ? $userMessage : $attachments[0]['filename']));
		}
		$conversation->setUpdated(new \DateTimeImmutable());
		$conversation->setTurnActivity(null);
		$conversation->setTurnProgress(null);
		$conversation->setTurnCancelled(null);
		$this->em->flush();

		$this->dispatcher->dispatch(
			$conversation->getId() ?? throw new \LogicException('The conversation has no id after flush.'),
			$userMessage,
			$attachments,
		);

		return $message;
	}

	/**
	 * Worker part: runs the (minutes-long) agent turn and stores the reply.
	 *
	 * No exception may escape back to the queue - a retry would deliver the same
	 * user message to the session again. Failures are stored as an error reply the
	 * frontend renders; when even that fails (closed EntityManager), they are only
	 * logged.
	 *
	 * @param list<array{fileId: string, mediaType: string, filename?: string}> $attachments
	 */
	public function processQueuedMessage(int|string $conversationId, string $userMessage, array $attachments = []): void
	{
		$conversation = $this->findConversation($conversationId);
		if ($conversation === null) {
			return;
		}

		try {
			$this->runConversationTurn($conversation, $userMessage, $attachments);
		} catch (\Throwable $e) {
			if ($e instanceof TurnTimeoutException) {
				// An exhausted turn budget is a deliberate safety stop against a runaway
				// agent, not an application failure.
				$this->logger?->warning(sprintf('AI chat conversation %s: %s', $conversationId, $e->getMessage()), ['exception' => $e]);
			} else {
				$this->logger?->error($e->getMessage(), ['exception' => $e]);
			}

			try {
				$this->addMessage($conversation, $this->describeError($e), MessageInterface::AUTHOR_AI, error: true);
				$conversation->setUpdated(new \DateTimeImmutable());
				$this->em->flush();
			} catch (\Throwable $saveError) {
				$this->logger?->error($saveError->getMessage(), ['exception' => $saveError]);
			}
		}
	}

	/**
	 * One turn through the engine (session, polling, tool round-trips); the reply is
	 * redacted and stored. A turn the user stopped ({@see cancelTurn()}) is stored as
	 * an interrupted reply with what the agent managed - not as an error - and the
	 * next message continues in the same session.
	 *
	 * @param list<array{fileId: string, mediaType: string, filename?: string}> $attachments
	 * @throws AiChatException
	 */
	public function runConversationTurn(ConversationInterface $conversation, string $userMessage, array $attachments = []): MessageInterface
	{
		$modelChoice = $this->getModelChoice($conversation);
		$result = $this->getRunner()->run(
			$conversation,
			$userMessage,
			// Persisted as soon as it changes, so a crashed turn resumes against the
			// right (new) session instead of the stuck old one.
			fn(?string $sessionId) => $this->em->flush(),
			array_map(
				static fn(array $a): Attachment => new Attachment((string) $a['fileId'], (string) $a['mediaType'], (string) ($a['filename'] ?? '')),
				$attachments,
			),
			fn(): string => $this->buildHistory($conversation),
			$modelChoice['model'] ?? null,
			$modelChoice['effort'] ?? null,
			new ConversationTurnMonitor($this->em, $conversation),
		);

		// Last line of defence: redact direct identifiers from the reply, including
		// literal values from personal-data columns no regex can recognise. The tasks
		// and reports of subagents reach the browser too.
		$piiValues = $this->piiValueCollector->collect($result->toolData);
		$text = $this->piiFilter->filter($result->text, $piiValues);
		$toolData = array_map(function (array $entry) use ($piiValues): array {
			if (($entry['tool_name'] ?? '') === 'subagent') {
				$entry['tool_input']['task'] = $this->piiFilter->filter((string) ($entry['tool_input']['task'] ?? ''), $piiValues);
				$entry['tool_result']['report'] = $this->piiFilter->filter((string) ($entry['tool_result']['report'] ?? ''), $piiValues);
			}

			return $entry;
		}, $result->toolData);

		$reply = $this->addMessage(
			$conversation,
			$text,
			MessageInterface::AUTHOR_AI,
			$toolData ?: null,
			$result->tokensInput ?: null,
			$result->tokensOutput ?: null,
			interrupted: $result->interrupted,
		);

		$conversation->setUpdated(new \DateTimeImmutable());
		$this->em->flush();

		return $reply;
	}

	/**
	 * Asks the running turn of the conversation to stop. The worker interrupts the
	 * session and stores what the agent managed as an interrupted reply; a turn that
	 * did not start yet ends right away. False when no turn is waiting for a reply.
	 */
	public function cancelTurn(ConversationInterface $conversation): bool
	{
		if (!$this->isTurnPending($conversation)) {
			return false;
		}

		$conversation->setTurnCancelled(new \DateTimeImmutable());
		$this->em->flush();

		return true;
	}

	/**
	 * The state of the turn waiting for a reply, for the widget; null when none waits.
	 * `activity` changes with every sign of life of the turn (the widget measures its
	 * timeout from it), `progress` lists the subagents at work.
	 *
	 * @return array{activity: string, cancelled: bool, progress: array{tool_calls: int, threads: list<array<string, mixed>>}|null}|null
	 */
	public function getTurnState(ConversationInterface $conversation): ?array
	{
		$last = $this->findLastMessage($conversation);
		if ($last === null || $last['author'] !== MessageInterface::AUTHOR_USER) {
			return null;
		}

		$activity = max($last['created'], $conversation->getTurnActivity() ?? $last['created']);

		return [
			'activity' => $activity->format(DATE_ATOM),
			'cancelled' => $conversation->getTurnCancelled() !== null,
			'progress' => $conversation->getTurnProgress(),
		];
	}

	/**
	 * Looks a server-side export (the export_csv tool) up by its token. The SQL comes
	 * from the stored, already validated tool input - it never travels to the browser.
	 *
	 * @return array{sql: string, filename: string, title: string}|null
	 */
	public function findExport(MessageInterface $message, string $token): ?array
	{
		if ($token === '') {
			return null;
		}

		foreach ($message->getToolData() ?? [] as $entry) {
			if (($entry['tool_name'] ?? '') !== 'export_csv') {
				continue;
			}

			$result = (array) ($entry['tool_result'] ?? []);
			$resultToken = (string) ($result['token'] ?? '');
			if (($result['type'] ?? '') !== 'export' || $resultToken === '' || !hash_equals($resultToken, $token)) {
				continue;
			}

			$sql = (string) ($entry['tool_input']['sql'] ?? '');

			return $sql !== '' ? [
				'sql' => $sql,
				'filename' => (string) ($result['filename'] ?? 'export.csv'),
				'title' => (string) ($result['title'] ?? ''),
			] : null;
		}

		return null;
	}

	/**
	 * The user-facing text of a failure: the (translated) message of an
	 * {@see AiChatException}, a generic text for anything else.
	 */
	public function describeError(\Throwable $e): string
	{
		if (!$e instanceof AiChatException) {
			return $this->translate('aiChat.error.unexpected', [], 'An unexpected error occurred. Please try again.');
		}

		$key = $e->getTranslationKey();

		return $key !== null ? $this->translate($key, $e->getTranslationParams(), $e->getMessage()) : $e->getMessage();
	}

	/**
	 * @param array<string, scalar> $params
	 */
	public function translate(string $key, array $params, string $default): string
	{
		return $this->translator !== null ? ($this->translator)($key, $params, $default) : $default;
	}

	// -- Internals -------------------------------------------------------------

	/**
	 * @param list<array<string, mixed>>|null $toolData
	 * @param list<array{fileId: string, mediaType: string, filename: string, size: int}>|null $attachments
	 * @param array{model: string|null, effort: string|null}|null $modelChoice null = that of the latest message
	 */
	protected function addMessage(
		ConversationInterface $conversation,
		string $content,
		string $author,
		?array $toolData = null,
		?int $tokensInput = null,
		?int $tokensOutput = null,
		bool $error = false,
		?array $attachments = null,
		?array $modelChoice = null,
		bool $interrupted = false,
	): MessageInterface {
		$class = $this->options->messageClass;
		// A reply runs on the choice of the message it answers - the latest one.
		$modelChoice ??= $this->getModelChoice($conversation);

		$message = (new $class())
			->setConversation($conversation)
			->setContent($content)
			->setAuthor($author)
			->setModel($modelChoice['model'] ?? null)
			->setEffort($modelChoice['effort'] ?? null)
			->setToolData($toolData)
			->setAttachments($attachments)
			->setTokensInput($tokensInput)
			->setTokensOutput($tokensOutput)
			->setError($error)
			->setInterrupted($interrupted)
			->setCreated(new \DateTimeImmutable());

		$this->em->persist($message);

		return $message;
	}

	/**
	 * @param list<AttachmentUpload> $uploads
	 * @return list<array{fileId: string, mediaType: string, filename: string, size: int}>
	 * @throws AiChatException
	 */
	protected function uploadAttachments(array $uploads): array
	{
		$attachments = [];
		foreach ($this->options->attachments->check($uploads) as ['upload' => $upload, 'mediaType' => $mediaType]) {
			$file = $this->client->uploadFile($upload->filename, $upload->contents, $mediaType);

			$fileId = (string) ($file['id'] ?? '');
			if ($fileId === '') {
				throw AttachmentPolicy::uploadFailed($upload);
			}

			$attachments[] = [
				'fileId' => $fileId,
				'mediaType' => $mediaType,
				'filename' => $upload->filename,
				'size' => $upload->size,
			];
		}

		return $attachments;
	}

	/**
	 * Refuses a message while the last one of the conversation still waits for its
	 * reply - e.g. two users writing into one conversation at the same time.
	 *
	 * @throws AiChatException
	 */
	protected function checkPendingTurn(ConversationInterface $conversation): void
	{
		if ($this->isTurnPending($conversation)) {
			throw AiChatException::translatable('The previous message is still being answered. Please wait for the reply.', 'aiChat.error.turnPending');
		}
	}

	/**
	 * Does the last message still wait for its reply? A turn without a sign of life
	 * (the message, the heartbeat of the worker) for {@see ChatOptions::$pendingTurnSeconds}
	 * counts as lost.
	 */
	protected function isTurnPending(ConversationInterface $conversation): bool
	{
		if ($conversation->getId() === null || $this->options->pendingTurnSeconds <= 0) {
			return false;
		}

		$last = $this->findLastMessage($conversation);
		if ($last === null || $last['author'] !== MessageInterface::AUTHOR_USER) {
			return false;
		}

		$activity = max($last['created'], $conversation->getTurnActivity() ?? $last['created']);

		return $activity >= new \DateTimeImmutable('-' . $this->options->pendingTurnSeconds . ' seconds');
	}

	/**
	 * @return array{author: string, created: \DateTimeImmutable}|null
	 */
	private function findLastMessage(ConversationInterface $conversation): ?array
	{
		if ($conversation->getId() === null) {
			return null;
		}

		return $this->em->createQueryBuilder()
			->select('m.author, m.created')
			->from($this->options->messageClass, 'm')
			->where('m.' . $this->options->conversationField . ' = :conversation')
			->setParameter('conversation', $conversation)
			->orderBy('m.' . $this->messageIdField(), 'DESC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();
	}

	/**
	 * Counts the user messages of the owner across all conversations - or, with
	 * {@see ChatOptions::$senderField}, the messages the sender wrote anywhere.
	 *
	 * @throws AiChatException
	 */
	protected function checkRateLimit(mixed $user): void
	{
		$limits = [
			['-1 minute', $this->options->rateLimitPerMinute, 'Rate limit exceeded. Please wait a moment before sending another message.', 'aiChat.error.rateLimitMinute'],
			['-1 hour', $this->options->rateLimitPerHour, 'Hourly rate limit exceeded. Please try again later.', 'aiChat.error.rateLimitHour'],
		];

		foreach ($limits as [$interval, $limit, $message, $key]) {
			if ($limit <= 0) {
				continue;
			}

			$qb = $this->em->createQueryBuilder()
				->select('COUNT(m)')
				->from($this->options->messageClass, 'm');

			if ($this->options->senderField !== null) {
				$qb->where('m.' . $this->options->senderField . ' = :user');
			} else {
				$qb->join('m.' . $this->options->conversationField, 'c')
					->where('c.' . $this->options->ownerField . ' = :user');
			}

			$count = (int) $qb
				->andWhere('m.author = :author')
				->andWhere('m.created >= :since')
				->setParameter('user', $user)
				->setParameter('author', MessageInterface::AUTHOR_USER)
				->setParameter('since', new \DateTimeImmutable($interval))
				->getQuery()
				->getSingleScalarResult();

			if ($count >= $limit) {
				throw AiChatException::translatable($message, $key, ['limit' => $limit]);
			}
		}
	}

	/** First words of the first message, on one line. */
	protected function createTitle(string $text): string
	{
		$line = trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $text));

		return mb_substr($line, 0, $this->options->titleMaxLength);
	}

	/**
	 * Composed lazily: the agent resolver depends on the runtime schema name and
	 * provisions or updates the agent before a new session when needed.
	 */
	protected function getRunner(): AgentTurnRunner
	{
		return $this->runner ??= new AgentTurnRunner(
			$this->client,
			$this->toolExecutor,
			$this->provisioner->resolver($this->queryExecutor->getSchemaName()),
			$this->turnOptions,
		);
	}

	private function userKey(mixed $user): string
	{
		if (!is_object($user)) {
			return (string) $user;
		}

		return implode('|', array_map('strval', $this->em->getClassMetadata($user::class)->getIdentifierValues($user)));
	}

	private function messageIdField(): string
	{
		return $this->em->getClassMetadata($this->options->messageClass)->getSingleIdentifierFieldName();
	}
}
