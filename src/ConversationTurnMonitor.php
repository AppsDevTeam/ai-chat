<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Entity\ConversationInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Connects a running turn with its conversation: writes the heartbeat and progress
 * the widget polls, and reads the stop request of the user.
 *
 * Both directions are throttled - the poll loop calls in every half a second.
 * The stop request is read with a query of its own: the worker holds the
 * conversation entity from before the turn and would never see the change.
 */
class ConversationTurnMonitor implements TurnMonitor
{
	private float $lastCancelCheck = 0;

	private bool $cancelled = false;

	private float $lastWrite = 0;

	private ?string $lastProgress = null;

	/** @var (callable(ConversationInterface): void)|null after a stored progress - a push to the widget */
	private $onChange;

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly ConversationInterface $conversation,
		private readonly float $cancelCheckSeconds = 1,
		private readonly float $heartbeatSeconds = 5,
		?callable $onChange = null,
	) {
		$this->onChange = $onChange;
	}

	public function isCancelled(): bool
	{
		$now = microtime(true);
		if ($this->cancelled || $now - $this->lastCancelCheck < $this->cancelCheckSeconds) {
			return $this->cancelled;
		}
		$this->lastCancelCheck = $now;

		$class = $this->em->getClassMetadata($this->conversation::class);

		$row = $this->em->createQueryBuilder()
			->select('c.turnCancelled')
			->from($class->getName(), 'c')
			->where('c.' . $class->getSingleIdentifierFieldName() . ' = :id')
			->setParameter('id', $this->conversation->getId())
			->getQuery()
			->getOneOrNullResult();

		// A conversation deleted during the turn has nobody waiting for the reply
		return $this->cancelled = $row === null || $row['turnCancelled'] !== null;
	}

	public function onProgress(array $progress): void
	{
		$now = microtime(true);
		$encoded = json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		if ($encoded === $this->lastProgress && $now - $this->lastWrite < $this->heartbeatSeconds) {
			return;
		}

		$this->lastWrite = $now;
		// A heartbeat (unchanged progress) is not pushed - nothing new to show
		$previous = $this->lastProgress;
		$this->lastProgress = $encoded;

		$this->conversation->setTurnActivity(new \DateTimeImmutable());
		$this->conversation->setTurnProgress($progress);
		$this->em->flush();
		if ($this->onChange !== null && $encoded !== $previous) {
			($this->onChange)($this->conversation);
		}
	}
}
