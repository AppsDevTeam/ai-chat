<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see PseudonymInterface}. Add your own id, the relation to
 * your conversation entity and the unique index (Doctrine does not read it from a
 * trait):
 *
 *   #[ORM\Entity]
 *   #[ORM\UniqueConstraint(name: 'uniq_ai_pseudonym_anchor', columns: ['conversation_id', 'anchor'])]
 *   class AiPseudonym implements PseudonymInterface
 *   {
 *       use PseudonymTrait;
 *
 *       #[ORM\ManyToOne(targetEntity: AiConversation::class)]
 *       #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
 *       protected ConversationInterface $conversation;
 *   }
 */
trait PseudonymTrait
{
	#[ORM\Column(length: 40)]
	protected string $anchor;

	#[ORM\Column(type: 'text')]
	#[Anonymize(AnonymizationType::NOTE)]
	protected string $value;

	public function getConversation(): ConversationInterface
	{
		return $this->conversation;
	}

	public function setConversation(ConversationInterface $conversation): static
	{
		$this->conversation = $conversation;
		return $this;
	}

	public function getAnchor(): string
	{
		return $this->anchor;
	}

	public function setAnchor(string $anchor): static
	{
		$this->anchor = $anchor;
		return $this;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function setValue(string $value): static
	{
		$this->value = $value;
		return $this;
	}
}
