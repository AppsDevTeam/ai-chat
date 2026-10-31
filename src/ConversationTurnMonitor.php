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

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly ConversationInterface $conversation,
		private readonly float $cancelCheckSeconds = 1,
		private readonly float $heartbeatSeconds = 5,
	) {
	}

	public function isCancelled(): bool
	{
		$now = microtime(true);
		if ($this->cancelled || $now - $this->lastCancelCheck < $this->cancelCheckSeconds) {
			return $this->cancelled;
		}
		$this->lastCancelCheck = $now;

		$class = $this->em->getClassMetadata($this->conversation::class);

		return $this->cancelled = $this->em->createQueryBuilder()
			->select('c.turnCancelled')
			->from($class->getName(), 'c')
			->where('c.' . $class->getSingleIdentifierFieldName() . ' = :id')
			->setParameter('id', $this->conversation->getId())
			->getQuery()
			->getSingleScalarResult() !== null;
	}

	public function onProgress(array $progress): void
	{
		$now = microtime(true);
		$encoded = json_encode($progress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		if ($encoded === $this->lastProgress && $now - $this->lastWrite < $this->heartbeatSeconds) {
			return;
		}

		$this->lastWrite = $now;
		$this->lastProgress = $encoded;

		$this->conversation->setTurnActivity(new \DateTimeImmutable());
		$this->conversation->setTurnProgress($progress);
		$this->em->flush();
	}
}
