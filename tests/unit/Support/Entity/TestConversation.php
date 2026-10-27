<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\ConversationTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_conversation')]
class TestConversation implements ConversationInterface
{
	use ConversationTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: TestOwner::class)]
	#[ORM\JoinColumn(nullable: false)]
	public TestOwner $owner;

	public function getId(): ?int
	{
		return $this->id;
	}
}
