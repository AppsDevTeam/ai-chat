<?php

declare(strict_types=1);

namespace App\AiChat\Ui;

use ADT\AiChat\Exception\AiChatException;
use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;
use App\AiChat\ChatService;
use App\AiChat\Entity\Message;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\UI\Control;

/**
 * The chat UI as a Nette component - renders ChatControl.latte and serves the
 * AJAX signals assets/aiChatControl.js talks to. Everything is scoped to the
 * signed-in user; ownership checks live in {@see ChatService::getConversation()}.
 *
 * The page needs Chart.js, marked and DOMPurify (see ChatPresenter.latte) plus
 * jQuery, and the two assets: ai-chat.css and aiChatControl.js.
 */
class ChatControl extends Control
{
	public function __construct(
		private readonly ChatService $chatService,
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly int $userId,
	) {
	}

	public function render(): void
	{
		$this->template->conversations = $this->chatService->getConversations($this->userId);
		$this->template->setFile(__DIR__ . '/ChatControl.latte');
		$this->template->render();
	}

	public function handleNewConversation(): void
	{
		$conversation = $this->chatService->createConversation($this->userId);

		$this->presenter->sendJson([
			'success' => true,
			'conversation' => [
				'id' => $conversation->getId(),
				'title' => '',
				'created' => $conversation->getCreated()->format('Y-m-d H:i'),
			],
		]);
	}

	public function handleLoadConversation(): void
	{
		$conversationId = (int) $this->presenter->getParameter('conversationId');
		$conversation = $this->chatService->getConversation($conversationId, $this->userId);

		if (!$conversation) {
			$this->presenter->sendJson(['success' => false, 'error' => 'Conversation not found.']);
			return;
		}

		$messages = [];
		foreach ($this->chatService->getMessages($conversation) as $message) {
			$messages[] = $this->clientMessage($message);
		}

		$this->presenter->sendJson([
			'success' => true,
			'conversation' => [
				'id' => $conversation->getId(),
				'title' => $conversation->getTitle(),
			],
			'messages' => $messages,
			'readOnly' => false,
			'contextUsage' => $this->chatService->getContextUsage($conversation),
		]);
	}

	public function handleSendMessage(): void
	{
		$conversationId = (int) $this->presenter->getHttpRequest()->getPost('conversationId');
		$text = (string) $this->presenter->getHttpRequest()->getPost('message');

		if (trim($text) === '') {
			$this->presenter->sendJson(['success' => false, 'error' => 'Message cannot be empty.']);
			return;
		}

		$conversation = $this->chatService->getConversation($conversationId, $this->userId);

		if (!$conversation) {
			$this->presenter->sendJson(['success' => false, 'error' => 'Conversation not found.']);
			return;
		}

		try {
			// Stores the message and dispatches the background job - the frontend
			// picks the reply up by polling (handlePollReply) from lastMessageId on.
			$userMessage = $this->chatService->sendMessage($conversation, $text);

			$this->presenter->sendJson([
				'success' => true,
				'queued' => true,
				'lastMessageId' => $userMessage->id,
				'conversation' => [
					'id' => $conversation->getId(),
					'title' => $conversation->getTitle(),
				],
			]);
		} catch (\Nette\Application\AbortException $e) {
			throw $e;
		} catch (AiChatException $e) {
			$this->presenter->sendJson(['success' => false, 'error' => $e->getMessage()]);
		} catch (\Throwable $e) {
			// log($e) with your logger of choice
			$this->presenter->sendJson(['success' => false, 'error' => 'An unexpected error occurred. Please try again.']);
		}
	}

	/**
	 * Polling for the reply processed in the background: returns messages newer
	 * than afterId (typically the id of the just-sent user message). The frontend
	 * stops polling once an AI message (reply or error) arrives.
	 */
	public function handlePollReply(): void
	{
		$conversationId = (int) $this->presenter->getParameter('conversationId');
		$afterId = (int) $this->presenter->getParameter('afterId');
		$conversation = $this->chatService->getConversation($conversationId, $this->userId);

		if (!$conversation) {
			$this->presenter->sendJson(['success' => false, 'error' => 'Conversation not found.']);
			return;
		}

		$messages = [];
		foreach ($this->chatService->getMessagesAfter($conversation, $afterId) as $message) {
			$messages[] = $this->clientMessage($message);
		}

		$this->presenter->sendJson([
			'success' => true,
			'messages' => $messages,
			'conversation' => [
				'id' => $conversation->getId(),
				'title' => $conversation->getTitle(),
			],
			'contextUsage' => $this->chatService->getContextUsage($conversation),
		]);
	}

	public function handleDeleteConversation(): void
	{
		$conversationId = (int) $this->presenter->getParameter('conversationId');
		$conversation = $this->chatService->getConversation($conversationId, $this->userId);

		if (!$conversation) {
			$this->presenter->sendJson(['success' => false, 'error' => 'Conversation not found.']);
			return;
		}

		$this->chatService->deleteConversation($conversation);

		$this->presenter->sendJson(['success' => true]);
	}

	public function handleGetConversations(): void
	{
		$conversations = [];
		foreach ($this->chatService->getConversations($this->userId) as $conversation) {
			$conversations[] = [
				'id' => $conversation->getId(),
				'title' => $conversation->getTitle() !== '' ? $conversation->getTitle() : 'New conversation',
				'updated' => $conversation->getUpdated()->format('j.n.Y H:i'),
			];
		}

		$this->presenter->sendJson([
			'success' => true,
			'conversations' => $conversations,
			'readOnly' => false,
		]);
	}

	/**
	 * Streamed download of a server-side CSV export (the export_csv tool). The SQL
	 * is NOT taken from the request - it is looked up by token in the stored
	 * (already validated) message, so the query never travels to the browser. The
	 * rows are streamed read-only from the anonymized views, never through the model.
	 */
	public function handleDownloadExport(): void
	{
		$conversationId = (int) $this->presenter->getParameter('conversationId');
		$messageId = (int) $this->presenter->getParameter('messageId');
		$token = (string) $this->presenter->getParameter('token');

		$conversation = $this->chatService->getConversation($conversationId, $this->userId);
		if ($conversation === null || $token === '') {
			$this->presenter->error('Export not found.');
			return;
		}

		$message = null;
		foreach ($this->chatService->getMessages($conversation) as $candidate) {
			if ($candidate->id === $messageId) {
				$message = $candidate;
				break;
			}
		}

		$export = $message !== null ? $this->findExport($message, $token) : null;
		if ($export === null) {
			$this->presenter->error('Export not found.');
			return;
		}

		// Validate BEFORE sending the file, so an error cannot corrupt half a CSV.
		try {
			$this->queryExecutor->validateQuery($export['sql']);
		} catch (QueryNotAllowedException) {
			$this->presenter->error('Invalid export query.');
			return;
		}

		$httpResponse = $this->presenter->getHttpResponse();
		$httpResponse->setContentType('text/csv', 'UTF-8');
		$httpResponse->setHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
		$httpResponse->setHeader('Cache-Control', 'no-store');

		$executor = $this->queryExecutor;
		$sql = $export['sql'];

		$this->presenter->sendResponse(new CallbackResponse(function () use ($executor, $sql): void {
			$out = fopen('php://output', 'w');
			fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders diacritics

			$first = true;
			$count = 0;

			try {
				$executor->streamQuery($sql, function (array $row) use (&$first, &$count, $out): void {
					if ($first) {
						fputcsv($out, array_keys($row), ',', '"', '');
						$first = false;
					}
					fputcsv($out, array_values($row), ',', '"', '');
					$count++;
				});

				if ($count === 0) {
					fputcsv($out, ['The export contains no data.'], ',', '"', '');
				}
			} catch (\Throwable $e) {
				// The response is already streaming - write the error into the file
				// instead of producing a mysteriously empty one. Log it as well.
				fputcsv($out, ['Export failed: ' . $e->getMessage()], ',', '"', '');
			}

			fclose($out);
		}));
	}

	/**
	 * The message in the shape the frontend expects; tool data filtered through
	 * {@see clientToolData()}.
	 *
	 * @return array<string, mixed>
	 */
	private function clientMessage(Message $message): array
	{
		$toolData = $message->toolData !== null
			? json_decode($message->toolData, true, 512, JSON_THROW_ON_ERROR)
			: null;

		return [
			'id' => $message->id,
			'author' => $message->author,
			'content' => $message->content,
			'tool_data' => $this->clientToolData($toolData, (int) $message->id),
			'error' => $message->error,
			'created' => $message->created->format('Y-m-d H:i:s'),
		];
	}

	/**
	 * Tool data allowed out to the browser: only displayable results (chart/table)
	 * and export metadata (token only). execute_sql / get_database_schema and ALL
	 * tool inputs are dropped, so database, table and column names - and the SQL
	 * itself - never reach the client.
	 *
	 * @param array<int, array<string, mixed>>|null $toolData
	 * @return array<int, array<string, mixed>>
	 */
	private function clientToolData(?array $toolData, int $messageId): array
	{
		$out = [];
		foreach ($toolData ?? [] as $entry) {
			$result = $entry['tool_result'] ?? [];
			$type = $result['type'] ?? null;

			if ($type === 'chart' || $type === 'table') {
				$out[] = ['tool_result' => $result];
			} elseif ($type === 'export') {
				$out[] = [
					'messageId' => $messageId,
					'tool_result' => [
						'type' => 'export',
						'title' => $result['title'] ?? '',
						'filename' => $result['filename'] ?? 'export.csv',
						'token' => $result['token'] ?? '',
					],
				];
			}
		}

		return $out;
	}

	/**
	 * Looks the stored export up by its token and returns the SQL + file name.
	 * The SQL comes from the persisted tool_input (server side only).
	 *
	 * @return array{sql: string, filename: string}|null
	 */
	private function findExport(Message $message, string $token): ?array
	{
		if ($message->toolData === null) {
			return null;
		}

		$toolData = json_decode($message->toolData, true, 512, JSON_THROW_ON_ERROR);
		foreach ($toolData ?? [] as $entry) {
			if (($entry['tool_name'] ?? '') !== 'export_csv') {
				continue;
			}

			$result = $entry['tool_result'] ?? [];
			$resultToken = (string) ($result['token'] ?? '');
			if (($result['type'] ?? '') !== 'export' || $resultToken === '' || !hash_equals($resultToken, $token)) {
				continue;
			}

			$sql = (string) ($entry['tool_input']['sql'] ?? '');
			if ($sql === '') {
				return null;
			}

			return ['sql' => $sql, 'filename' => (string) ($result['filename'] ?? 'export.csv')];
		}

		return null;
	}
}
