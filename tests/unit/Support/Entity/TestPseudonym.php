<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Pseudonymization\PseudonymInterface;
use ADT\AiChat\Pseudonymization\PseudonymTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_pseudonym')]
#[ORM\UniqueConstraint(name: 'uniq_test_pseudonym_anchor', columns: ['conversation_id', 'anchor'])]
class TestPseudonym implements PseudonymInterface
{
	use PseudonymTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: TestConversation::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	protected ConversationInterface $conversation;
}
