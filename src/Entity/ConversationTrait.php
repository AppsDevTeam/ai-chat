<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use ADT\DoctrineAnonymization\Attributes\Description;
use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see ConversationInterface}. Add your own id and owner
 * relation on the entity.
 *
 * The columns carry safe anonymization defaults: the title comes from the first
 * user message (free text - may contain anything the user typed) and the session
 * id is an access token to the server-side conversation, so neither may appear
 * readable in the anonymized views.
 */
trait ConversationTrait
{
	#[ORM\Column(length: 255, options: ['default' => ''])]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('Conversation title derived from the first few words of the first user message.')]
	protected string $title = '';

	/** Managed Agents session lives on Anthropic's side; only its id is stored. */
	#[ORM\Column(name: 'mca_session_id', length: 64, nullable: true)]
	#[Anonymize(AnonymizationType::SECRET)]
	#[Description('Anthropic Managed Agents session ID holding the server-side conversation state.')]
	protected ?string $sessionId = null;

	#[ORM\Column(options: ['default' => false])]
	#[Description('Soft-delete flag; true means the conversation is deleted and no longer shown to the user.')]
	protected bool $deleted = false;

	#[ORM\Column]
	#[Description('Date and time when the conversation was created.')]
	protected \DateTimeImmutable $created;

	#[ORM\Column]
	#[Description('Date and time of the last update to the conversation (e.g. a new message was added).')]
	protected \DateTimeImmutable $updated;

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): static
	{
		$this->title = $title;
		return $this;
	}

	public function getSessionId(): ?string
	{
		return $this->sessionId;
	}

	public function setSessionId(?string $sessionId): static
	{
		$this->sessionId = $sessionId;
		return $this;
	}

	public function isDeleted(): bool
	{
		return $this->deleted;
	}

	public function setDeleted(bool $deleted): static
	{
		$this->deleted = $deleted;
		return $this;
	}

	public function getCreated(): \DateTimeImmutable
	{
		return $this->created;
	}

	public function getUpdated(): \DateTimeImmutable
	{
		return $this->updated;
	}

	public function setUpdated(\DateTimeImmutable $updated): static
	{
		$this->updated = $updated;
		return $this;
	}
}
