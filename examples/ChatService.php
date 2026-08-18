<?php

declare(strict_types=1);

namespace App\AiChat;

use ADT\AiChat\AgentProvisioner;
use ADT\AiChat\AgentTurnRunner;
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

	/**
	 * Web request part: cheap and fast. Add your own rate limiting here.
	 */
	public function sendMessage(Conversation $conversation, string $userMessage): Message
	{
		$message = new Message($conversation, $userMessage);
		$this->em->persist($message);

		if ($conversation->getTitle() === '') {
			$conversation->setTitle(mb_substr($userMessage, 0, 100));
		}
		$conversation->setUpdated(new \DateTimeImmutable());
		$this->em->flush();

		$this->dispatcher->dispatch($conversation->getId(), $userMessage);

		return $message;
	}

	/**
	 * Worker part: runs the (minutes-long) agent turn and stores the reply.
	 *
	 * Never let an exception escape back to the queue - a retry would deliver the
	 * same user message to the session again. Store failures as an error message
	 * instead, so the frontend can render them.
	 */
	public function processMessage(int $conversationId, string $userMessage): void
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
