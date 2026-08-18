<?php

declare(strict_types=1);

namespace App\AiChat;

use ADT\AiChat\AgentProvisioner;
use ADT\AiChat\AgentTurnRunner;
use ADT\AiChat\Attachment;
use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Dispatch\MessageDispatcher;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\PiiResponseFilter;
use ADT\AiChat\PiiValueCollector;
use ADT\AiChat\ToolHandler;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;
use App\AiChat\Entity\Conversation;
use App\AiChat\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reference coordinator showing the whole life of a message. This is the class
 * that belongs to YOUR project - ownership, rate limits and persistence differ
 * everywhere, so the package does not ship it.
 *
 * The flow splits in two, because an agent turn takes minutes and must never run
 * inside a web request:
 *
 *   web request:  sendMessage()  - store the user message, hand off to background
 *   worker:       processMessage() - run the turn, redact, store the reply
 *
 * The hand-off goes through your {@see MessageDispatcher} implementation (a queue
 * publish, typically); the worker consumes the queue and calls processMessage().
 */
class ChatService
{
	private ?AgentTurnRunner $runner = null;

	public function __construct(
		private readonly ManagedAgentsClient $client,
		private readonly ToolHandler $toolHandler,
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly AgentProvisioner $provisioner,
		private readonly MessageDispatcher $dispatcher,
		private readonly PiiResponseFilter $piiFilter,
		private readonly PiiValueCollector $piiValueCollector,
		private readonly EntityManagerInterface $em,
	) {
	}

	// -- Conversation CRUD the UI control needs -------------------------------

	public function createConversation(int $userId): Conversation
	{
		$conversation = new Conversation($userId);
		$this->em->persist($conversation);
		$this->em->flush();

		return $conversation;
	}

	/**
	 * @return list<Conversation>
	 */
	public function getConversations(int $userId): array
	{
		return $this->em->getRepository(Conversation::class)->findBy(
			['userId' => $userId, 'deleted' => false],
			['updated' => 'DESC'],
		);
	}

	/** Ownership is enforced here - a foreign conversation reads as "not found". */
	public function getConversation(int $conversationId, int $userId): ?Conversation
	{
		$conversation = $this->em->find(Conversation::class, $conversationId);

		if ($conversation === null || $conversation->isDeleted() || $conversation->userId !== $userId) {
			return null;
		}

		return $conversation;
	}

	/**
	 * @return list<Message>
	 */
	public function getMessages(Conversation $conversation): array
	{
		return $this->em->getRepository(Message::class)->findBy(
			['conversation' => $conversation],
			['created' => 'ASC', 'id' => 'ASC'],
		);
	}

	/**
	 * Messages newer than $afterId - the frontend polls with the id of the message
	 * it just sent and stops once the AI reply (or an error message) arrives.
	 *
	 * @return list<Message>
	 */
	public function getMessagesAfter(Conversation $conversation, int $afterId): array
	{
		return $this->em->getRepository(Message::class)->createQueryBuilder('m')
			->where('m.conversation = :conversation')
			->andWhere('m.id > :afterId')
			->setParameter('conversation', $conversation)
			->setParameter('afterId', $afterId)
			->orderBy('m.id', 'ASC')
			->getQuery()
			->getResult();
	}

	public function deleteConversation(Conversation $conversation): void
	{
		$conversation->setDeleted(true);
		$this->em->flush();
	}

	/**
	 * Context-window usage estimate for the UI gauge. Managed Agents resends the
	 * whole session context on every turn, so the tokensInput of the latest AI
	 * message approximates the current window usage.
	 *
	 * @return array{used: int, window: int, percent: int}
	 */
	public function getContextUsage(Conversation $conversation, int $window = 200000): array
	{
		$row = $this->em->getRepository(Message::class)->createQueryBuilder('m')
			->select('m.tokensInput')
			->where('m.conversation = :conversation')
			->andWhere('m.tokensInput IS NOT NULL')
			->setParameter('conversation', $conversation)
			->orderBy('m.id', 'DESC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();

		$used = (int) ($row['tokensInput'] ?? 0);

		return [
			'used' => $used,
			'window' => $window,
			'percent' => $window > 0 ? min(100, (int) round($used / $window * 100)) : 0,
		];
	}

	// -- The message flow ------------------------------------------------------

	/**
	 * Web request part: cheap and fast. Add your own rate limiting here.
	 *
	 * Attachments arrive as uploaded file data; they are pushed to the Anthropic
	 * Files API right away (seconds, acceptable in a web request) and only the
	 * returned file ids travel through the queue - the worker replays them as
	 * content blocks of the user message.
	 *
	 * @param list<array{filename: string, contents: string, mediaType: string}> $uploads
	 */
	public function sendMessage(Conversation $conversation, string $userMessage, array $uploads = []): Message
	{
		$attachments = [];
		foreach ($uploads as $upload) {
			$file = $this->client->uploadFile($upload['filename'], $upload['contents'], $upload['mediaType']);
			$attachments[] = [
				'fileId' => (string) $file['id'],
				'mediaType' => $upload['mediaType'],
				'filename' => $upload['filename'],
			];
		}

		$message = new Message($conversation, $userMessage);
		$this->em->persist($message);

		if ($conversation->getTitle() === '') {
			$conversation->setTitle(mb_substr($userMessage, 0, 100));
		}
		$conversation->setUpdated(new \DateTimeImmutable());
		$this->em->flush();

		$this->dispatcher->dispatch($conversation->getId(), $userMessage, $attachments);

		return $message;
	}

	/**
	 * Worker part: runs the (minutes-long) agent turn and stores the reply.
	 *
	 * Never let an exception escape back to the queue - a retry would deliver the
	 * same user message to the session again. Store failures as an error message
	 * instead, so the frontend can render them.
	 */
	public function processMessage(int $conversationId, string $userMessage, array $attachments = []): void
	{
		$conversation = $this->em->find(Conversation::class, $conversationId);
		if ($conversation === null || $conversation->isDeleted()) {
			return;
		}

		try {
			$result = $this->getRunner()->run(
				$conversation,
				$userMessage,
				// Persist the session id as soon as it changes, so a crashed turn
				// resumes against the right session next time.
				fn(?string $sessionId) => $this->em->flush(),
				array_map(
					static fn(array $a) => new Attachment($a['fileId'], $a['mediaType'], $a['filename'] ?? ''),
					$attachments,
				),
			);

			// Last line of defence: redact direct identifiers from the reply,
			// including literal values that appeared in personal-data columns.
			$text = $this->piiFilter->filter(
				$result->text,
				$this->piiValueCollector->collect($result->toolData),
			);

			$reply = new Message($conversation, $text);
			$reply->author = Message::AUTHOR_AI;
			$reply->toolData = $result->toolData
				? json_encode($result->toolData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
				: null;
			$reply->tokensInput = $result->tokensInput;
			$reply->tokensOutput = $result->tokensOutput;
		} catch (\Throwable $e) {
			// log($e) with your logger of choice
			$reply = new Message(
				$conversation,
				$e instanceof AiChatException ? $e->getMessage() : 'An unexpected error occurred. Please try again.',
			);
			$reply->author = Message::AUTHOR_AI;
			$reply->error = true;
		}

		$this->em->persist($reply);
		$conversation->setUpdated(new \DateTimeImmutable());
		$this->em->flush();
	}

	/**
	 * Composed lazily: the agent resolver depends on the runtime schema name and
	 * fails with a helpful message when provisioning has not run yet.
	 */
	private function getRunner(): AgentTurnRunner
	{
		return $this->runner ??= new AgentTurnRunner(
			$this->client,
			$this->toolHandler,
			$this->provisioner->resolver($this->queryExecutor->getSchemaName()),
		);
	}
}
