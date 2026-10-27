<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Entity\MessageTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_message')]
class TestMessage implements MessageInterface
{
	use MessageTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: TestConversation::class)]
	#[ORM\JoinColumn(nullable: false)]
	protected ConversationInterface $conversation;

	public function getId(): ?int
	{
		return $this->id;
	}
}
