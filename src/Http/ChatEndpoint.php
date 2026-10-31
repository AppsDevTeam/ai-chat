<?php

declare(strict_types=1);

namespace ADT\AiChat\Http;

use ADT\AiChat\AttachmentUpload;
use ADT\AiChat\ChatService;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\ToolHandler;
use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;
use Psr\Log\LoggerInterface;

/**
 * The server side of the browser widget (assets/ai-chat.js), framework neutral:
 * every action returns the JSON payload as an array - send it with whatever your
 * framework uses (a Nette signal + sendJson(), a Symfony controller, ...).
 *
 * Failures come back as `{success: false, error}` with a user-facing message, so
 * the caller never needs a try/catch.
 *
 * The endpoint only ever works with conversations of the passed owner. Deciding
 * WHO is the owner (the signed-in user, or another one in a read-only view for a
 * privileged role) is the caller's job - and so is refusing send/delete in such a
 * read-only view.
 *
 * Two flags tell the widget what the viewer may do: `readOnly` hides the input,
 * `canManage` (default: not read-only) allows rating and deleting. A colleague who
 * may continue someone's conversation gets readOnly false + canManage false, and
 * with {@see \ADT\AiChat\ChatOptions::$senderField} the user messages carry
 * `sender: {name, self, owner}` ({@see setSenderFormatter()}).
 *
 * What reaches the browser is whitelisted ({@see clientToolData()}): only
 * displayable results and export tokens. SQL, schema and table names stay on the
 * server.
 */
class ChatEndpoint
{
	/** @var (callable(mixed): string)|null */
	private $senderFormatter = null;

	public function __construct(
		private readonly ChatService $chat,
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * The name the widget shows above a user message of another sender. Without it
	 * a Stringable or scalar sender is cast to string.
	 *
	 * @param callable(mixed $sender): string $formatter
	 */
	public function setSenderFormatter(callable $formatter): static
	{
		$this->senderFormatter = $formatter;
		return $this;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function conversations(object|int|string $owner, bool $readOnly = false, ?bool $canManage = null): array
	{
		return $this->guard(fn(): array => [
			'success' => true,
			'conversations' => array_map($this->clientConversation(...), $this->chat->getConversations($owner)),
			'readOnly' => $readOnly,
			'canManage' => $canManage ?? !$readOnly,
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function create(object|int|string $owner): array
	{
		return $this->guard(fn(): array => [
			'success' => true,
			'conversation' => $this->clientConversation($this->chat->createConversation($owner)),
		]);
	}

	/**
	 * $viewer (the signed-in user) marks the messages they wrote as `sender.self`.
	 *
	 * @return array<string, mixed>
	 */
	public function load(
		object|int|string $owner,
		int|string $conversationId,
		bool $readOnly = false,
		?bool $canManage = null,
		object|int|string|null $viewer = null,
	): array {
		return $this->guard(function () use ($owner, $conversationId, $readOnly, $canManage, $viewer): array {
			$conversation = $this->requireConversation($conversationId, $owner);

			return [
				'success' => true,
				'conversation' => $this->clientConversation($conversation),
				'messages' => array_map(fn(MessageInterface $m): array => $this->clientMessage($m, $viewer ?? $owner), $this->chat->getMessages($conversation)),
				'readOnly' => $readOnly,
				'canManage' => $canManage ?? !$readOnly,
				'contextUsage' => $this->chat->getContextUsage($conversation),
			];
		});
	}

	/**
	 * Stores the message and dispatches the background turn; the reply is picked up
	 * by {@see poll()} from `lastMessageId` on. Without a conversation id a new
	 * conversation is started, so the first message needs one request only.
	 *
	 * The model and effort (the widget sends them while the conversation has no
	 * message yet) are validated against {@see \ADT\AiChat\ModelSelection}.
	 *
	 * $sender writes into the conversation of $owner (null = the owner itself); a new
	 * conversation always belongs to $owner.
	 *
	 * @param list<AttachmentUpload> $uploads
	 * @return array<string, mixed>
	 */
	public function send(
		object|int|string $owner,
		int|string|null $conversationId,
		string $message,
		array $uploads = [],
		?string $model = null,
		?string $effort = null,
		object|int|string|null $sender = null,
	): array {
		return $this->guard(function () use ($owner, $conversationId, $message, $uploads, $model, $effort, $sender): array {
			$conversation = $conversationId !== null && $conversationId !== '' && $conversationId !== 0
				? $this->requireConversation($conversationId, $owner)
				: $this->chat->createConversation($owner);

			$userMessage = $this->chat->enqueueMessage($conversation, $message, $uploads, $model, $effort, $sender);

			return [
				'success' => true,
				'queued' => true,
				'message' => $this->clientMessage($userMessage, $sender ?? $owner),
				'lastMessageId' => $userMessage->getId(),
				'conversation' => $this->clientConversation($conversation),
			];
		});
	}

	/**
	 * Messages newer than $afterId. The widget stops polling once an AI message
	 * (a reply or an error) arrives, or on `success: false`. While the reply is on its
	 * way, `turn` carries its state ({@see ChatService::getTurnState()}): the widget
	 * shows the subagents at work and measures its timeout from the last activity.
	 *
	 * @return array<string, mixed>
	 */
	public function poll(object|int|string $owner, int|string $conversationId, int|string $afterId, object|int|string|null $viewer = null): array
	{
		return $this->guard(function () use ($owner, $conversationId, $afterId, $viewer): array {
			$conversation = $this->requireConversation($conversationId, $owner);

			return [
				'success' => true,
				'messages' => array_map(fn(MessageInterface $m): array => $this->clientMessage($m, $viewer ?? $owner), $this->chat->getMessagesAfter($conversation, $afterId)),
				'conversation' => $this->clientConversation($conversation),
				'contextUsage' => $this->chat->getContextUsage($conversation),
				'turn' => $this->clientTurn($this->chat->getTurnState($conversation)),
			];
		});
	}

	/**
	 * Stops the reply being worked on (the Stop button of the widget). The reply
	 * still arrives through {@see poll()} - interrupted, with what the agent managed.
	 * Refuse it in a read-only view.
	 *
	 * @return array<string, mixed>
	 */
	public function cancel(object|int|string $owner, int|string $conversationId): array
	{
		return $this->guard(function () use ($owner, $conversationId): array {
			return [
				'success' => true,
				'cancelled' => $this->chat->cancelTurn($this->requireConversation($conversationId, $owner)),
			];
		});
	}

	/**
	 * The owner's rating of the conversation ({@see ConversationInterface::RATING_UP} or
	 * RATING_DOWN, null clears it) with an optional comment. Refuse it in a read-only
	 * view - only the owner rates their conversation.
	 *
	 * @return array<string, mixed>
	 */
	public function rate(object|int|string $owner, int|string $conversationId, ?int $rating, ?string $comment = null): array
	{
		return $this->guard(function () use ($owner, $conversationId, $rating, $comment): array {
			$conversation = $this->requireConversation($conversationId, $owner);
			$this->chat->rateConversation($conversation, $rating, $comment);

			return [
				'success' => true,
				'conversation' => $this->clientConversation($conversation),
			];
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	public function delete(object|int|string $owner, int|string $conversationId): array
	{
		return $this->guard(function () use ($owner, $conversationId): array {
			$this->chat->deleteConversation($this->requireConversation($conversationId, $owner));

			return ['success' => true];
		});
	}

	/**
	 * A server-side CSV export of the export_csv tool, or null when the token does
	 * not match (answer 404). The SQL is looked up in the stored message, not taken
	 * from the request.
	 *
	 * @throws AiChatException when the stored query no longer passes validation
	 */
	public function export(object|int|string $owner, int|string $conversationId, int|string $messageId, string $token): ?CsvExport
	{
		$conversation = $this->chat->getConversation($conversationId, $owner);
		$message = $conversation !== null ? $this->chat->getMessage($conversation, $messageId) : null;
		$export = $message !== null ? $this->chat->findExport($message, $token) : null;

		if ($export === null) {
			return null;
		}

		// Validate BEFORE the file starts streaming.
		try {
			$this->queryExecutor->validateQuery($export['sql']);
		} catch (QueryNotAllowedException $e) {
			throw AiChatException::translatable('Invalid export query.', 'aiChat.error.invalidExport', [], $e);
		}

		$filename = basename(str_replace('\\', '/', $export['filename']));

		return new CsvExport(
			$this->queryExecutor,
			$export['sql'],
			$filename !== '' ? $filename : 'export.csv',
			$this->chat->translate('aiChat.export.empty', [], 'The export contains no data.'),
			$this->chat->translate('aiChat.export.failed', [], 'Export failed'),
			fn(\Throwable $e) => $this->logger?->error($e->getMessage(), ['exception' => $e]),
		);
	}

	/**
	 * @return array{id: int|string|null, title: string, created: string, updated: string, model: string|null, effort: string|null, rating: int|null, ratingComment: string|null}
	 */
	public function clientConversation(ConversationInterface $conversation): array
	{
		$modelChoice = $this->chat->getModelChoice($conversation);

		return [
			'id' => $conversation->getId(),
			'title' => $conversation->getTitle(),
			'created' => $conversation->getCreated()->format(DATE_ATOM),
			'updated' => $conversation->getUpdated()->format(DATE_ATOM),
			'model' => $modelChoice['model'] ?? null,
			'effort' => $modelChoice['effort'] ?? null,
			'rating' => $conversation->getRating(),
			'ratingComment' => $conversation->getRatingComment(),
		];
	}

	/**
	 * The message in the shape the widget expects. A user message stored with a
	 * sender carries `sender: {name, self, owner}` - self when $viewer wrote it, owner
	 * when the owner of the conversation did; `model` and
	 * `effort` are the ones the conversation ran on for the message.
	 *
	 * @return array<string, mixed>
	 */
	public function clientMessage(MessageInterface $message, object|int|string|null $viewer = null): array
	{
		$sender = $this->chat->getSender($message);

		return [
			'id' => $message->getId(),
			'author' => $message->getAuthor(),
			'sender' => $sender !== null ? [
				'name' => $this->formatSender($sender),
				'self' => $this->chat->isSameUser($sender, $viewer),
				'owner' => $this->chat->isSameUser($sender, $this->chat->getOwner($message->getConversation())),
			] : null,
			'content' => $message->getContent(),
			'tool_data' => $this->clientToolData($message->getToolData(), $message->getId()),
			'attachments' => array_map(static fn(array $attachment): array => [
				'filename' => (string) ($attachment['filename'] ?? ''),
				'mediaType' => (string) ($attachment['mediaType'] ?? ''),
				'size' => (int) ($attachment['size'] ?? 0),
			], $message->getAttachments() ?? []),
			'model' => $message->getModel(),
			'effort' => $message->getEffort(),
			'error' => $message->isError(),
			// What the AI received instead of the question (anchors), shown above the reply
			'sent_content' => $message->isError() ? null : $message->getSentContent(),
			'interrupted' => $message->isInterrupted(),
			'created' => $message->getCreated()->format(DATE_ATOM),
		];
	}

	/**
	 * The turn state for the widget - subagents with their name, status and number
	 * of tool calls; their tasks stay on the server (they may quote table names).
	 *
	 * @param array{activity: string, cancelled: bool, progress: array<string, mixed>|null}|null $state
	 * @return array<string, mixed>|null
	 */
	public function clientTurn(?array $state): ?array
	{
		if ($state === null) {
			return null;
		}

		return [
			'activity' => $state['activity'],
			'cancelled' => $state['cancelled'],
			'toolCalls' => (int) ($state['progress']['tool_calls'] ?? 0),
			'subagents' => array_map(static fn(array $thread): array => [
				'id' => (string) ($thread['id'] ?? ''),
				'name' => (string) ($thread['name'] ?? ''),
				'status' => (string) ($thread['status'] ?? ''),
				'toolCalls' => (int) ($thread['tool_calls'] ?? 0),
			], $state['progress']['threads'] ?? []),
		];
	}

	/**
	 * Tool data allowed out to the browser: only displayable results (chart/table)
	 * and export metadata (token only). execute_sql / get_database_schema and ALL
	 * tool inputs are dropped, so database, table and column names - and the SQL
	 * itself - never reach the client.
	 *
	 * A subagent comes as `{type: subagent, thread, name, status, report}` - its report
	 * is a redacted text like the reply; the results it rendered carry its `thread`,
	 * so the widget shows them inside the subagent card. The task is left out like
	 * every tool input.
	 *
	 * @param list<array<string, mixed>>|null $toolData
	 * @return list<array<string, mixed>>
	 */
	public function clientToolData(?array $toolData, int|string|null $messageId): array
	{
		$out = [];
		foreach ($toolData ?? [] as $entry) {
			$result = (array) ($entry['tool_result'] ?? []);
			$type = $result['type'] ?? null;
			$thread = isset($entry['thread']) ? ['thread' => (string) $entry['thread']] : [];

			if (($entry['tool_name'] ?? '') === 'subagent') {
				$out[] = ['tool_result' => [
					'type' => 'subagent',
					'thread' => (string) ($entry['tool_input']['thread'] ?? ''),
					'name' => (string) ($entry['tool_input']['name'] ?? ''),
					'status' => (string) ($result['status'] ?? ''),
					'report' => (string) ($result['report'] ?? ''),
				]];
			} elseif ($type === 'chart' || $type === 'table') {
				$out[] = ['tool_result' => ToolHandler::normalizeRenderResult($result)] + $thread;
			} elseif ($type === 'export') {
				$out[] = [
					'messageId' => $messageId,
					'tool_result' => [
						'type' => 'export',
						'title' => (string) ($result['title'] ?? ''),
						'filename' => (string) ($result['filename'] ?? 'export.csv'),
						'token' => (string) ($result['token'] ?? ''),
					],
				] + $thread;
			}
		}

		return $out;
	}

	private function formatSender(mixed $sender): string
	{
		if ($this->senderFormatter !== null) {
			return ($this->senderFormatter)($sender);
		}

		return is_scalar($sender) || $sender instanceof \Stringable ? (string) $sender : '';
	}

	/**
	 * @throws AiChatException
	 */
	private function requireConversation(int|string $conversationId, object|int|string $owner): ConversationInterface
	{
		return $this->chat->getConversation($conversationId, $owner)
			?? throw AiChatException::translatable('Conversation not found.', 'aiChat.error.conversationNotFound');
	}

	/**
	 * @param callable(): array<string, mixed> $action
	 * @return array<string, mixed>
	 */
	private function guard(callable $action): array
	{
		try {
			return $action();
		} catch (AiChatException $e) {
			return ['success' => false, 'error' => $this->chat->describeError($e)];
		} catch (\Throwable $e) {
			$this->logger?->error($e->getMessage(), ['exception' => $e]);

			return ['success' => false, 'error' => $this->chat->describeError($e)];
		}
	}
}
