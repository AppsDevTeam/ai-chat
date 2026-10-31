<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Pseudonymization\PiiCheckListener;
use ADT\AiChat\Pseudonymization\PiiCheckUnavailableException;
use ADT\AiChat\Pseudonymization\PseudonymizedText;

final class RecordingPiiCheckListener implements PiiCheckListener
{
	/** @var list<array{0: string, 1: int|string|null, 2: PseudonymizedText|PiiCheckUnavailableException}> */
	public array $events = [];

	public function checked(ConversationInterface $conversation, PseudonymizedText $result): void
	{
		$this->events[] = ['checked', $conversation->getId(), $result];
	}

	public function unavailable(ConversationInterface $conversation, PiiCheckUnavailableException $exception): void
	{
		$this->events[] = ['unavailable', $conversation->getId(), $exception];
	}
}
