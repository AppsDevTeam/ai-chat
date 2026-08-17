<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\ConversationTrait;

class FakeConversation implements ConversationInterface
{
	use ConversationTrait;

	public function __construct(private readonly int $id = 1)
	{
		$this->created = new \DateTimeImmutable();
		$this->updated = new \DateTimeImmutable();
	}

	public function getId(): int
	{
		return $this->id;
	}
}
