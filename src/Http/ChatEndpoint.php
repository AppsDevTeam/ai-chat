<?php

declare(strict_types=1);

namespace ADT\AiChat\Http;

use ADT\AiChat\AttachmentUpload;
use ADT\AiChat\ChatService;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Exception\AiChatException;
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
 * What reaches the browser is whitelisted ({@see clientToolData()}): only
 * displayable results and export tokens. SQL, schema and table names stay on the
 * server.
 */
class ChatEndpoint
{
	public function __construct(
		private readonly ChatService $chat,
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function conversations(object|int|string $owner, bool $readOnly = false): array
	{
		return $this->guard(fn(): array => [
			'success' => true,
			'conversations' => array_map($this->clientConversation(...), $this->chat->getConversations($owner)),
			'readOnly' => $readOnly,
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
	 * @return array<string, mixed>
	 */
	public function load(object|int|string $owner, int|string $conversationId, bool $readOnly = false): array
	{
		return $this->guard(function () use ($owner, $conversationId, $readOnly): array {
			$conversation = $this->requireConversation($conversationId, $owner);

			return [
				'success' => true,
				'conversation' => $this->clientConversation($conversation),
				'messages' => array_map($this->clientMessage(...), $this->chat->getMessages($conversation)),
				'readOnly' => $readOnly,
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
	): array {
		return $this->guard(function () use ($owner, $conversationId, $message, $uploads, $model, $effort): array {
			$conversation = $conversationId !== null && $conversationId !== '' && $conversationId !== 0
				? $this->requireConversation($conversationId, $owner)
				: $this->chat->createConversation($owner);

			$userMessage = $this->chat->enqueueMessage($conversation, $message, $uploads, $model, $effort);

			return [
				'success' => true,
				'queued' => true,
				'message' => $this->clientMessage($userMessage),
				'lastMessageId' => $userMessage->getId(),
				'conversation' => $this->clientConversation($conversation),
			];
		});
	}

	/**
	 * Messages newer than $afterId. The widget stops polling once an AI message
	 * (a reply or an error) arrives, or on `success: false`.
	 *
	 * @return array<string, mixed>
	 */
	public function poll(object|int|string $owner, int|string $conversationId, int|string $afterId): array
	{
		return $this->guard(function () use ($owner, $conversationId, $afterId): array {
			$conversation = $this->requireConversation($conversationId, $owner);

			return [
				'success' => true,
				'messages' => array_map($this->clientMessage(...), $this->chat->getMessagesAfter($conversation, $afterId)),
				'conversation' => $this->clientConversation($conversation),
				'contextUsage' => $this->chat->getContextUsage($conversation),
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
		return [
			'id' => $conversation->getId(),
			'title' => $conversation->getTitle(),
			'created' => $conversation->getCreated()->format(DATE_ATOM),
			'updated' => $conversation->getUpdated()->format(DATE_ATOM),
			'model' => $conversation->getModel(),
			'effort' => $conversation->getEffort(),
			'rating' => $conversation->getRating(),
			'ratingComment' => $conversation->getRatingComment(),
		];
	}

	/**
	 * The message in the shape the widget expects.
	 *
	 * @return array<string, mixed>
	 */
	public function clientMessage(MessageInterface $message): array
	{
		return [
			'id' => $message->getId(),
			'author' => $message->getAuthor(),
			'content' => $message->getContent(),
			'tool_data' => $this->clientToolData($message->getToolData(), $message->getId()),
			'attachments' => array_map(static fn(array $attachment): array => [
				'filename' => (string) ($attachment['filename'] ?? ''),
				'mediaType' => (string) ($attachment['mediaType'] ?? ''),
				'size' => (int) ($attachment['size'] ?? 0),
			], $message->getAttachments() ?? []),
			'error' => $message->isError(),
			'created' => $message->getCreated()->format(DATE_ATOM),
		];
	}

	/**
	 * Tool data allowed out to the browser: only displayable results (chart/table)
	 * and export metadata (token only). execute_sql / get_database_schema and ALL
	 * tool inputs are dropped, so database, table and column names - and the SQL
	 * itself - never reach the client.
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

			if ($type === 'chart' || $type === 'table') {
				$out[] = ['tool_result' => $result];
			} elseif ($type === 'export') {
				$out[] = [
					'messageId' => $messageId,
					'tool_result' => [
						'type' => 'export',
						'title' => (string) ($result['title'] ?? ''),
						'filename' => (string) ($result['filename'] ?? 'export.csv'),
						'token' => (string) ($result['token'] ?? ''),
					],
				];
			}
		}

		return $out;
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
