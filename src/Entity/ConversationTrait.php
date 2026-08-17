<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see ConversationInterface}. Add your own id and owner
 * relation on the entity.
 */
trait ConversationTrait
{
	#[ORM\Column(length: 255)]
	protected string $title = '';

	/** Managed Agents session lives on Anthropic's side; only its id is stored. */
	#[ORM\Column(name: 'mca_session_id', length: 64, nullable: true)]
	protected ?string $sessionId = null;

	#[ORM\Column(options: ['default' => false])]
	protected bool $deleted = false;

	#[ORM\Column]
	protected \DateTimeImmutable $created;

	#[ORM\Column]
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
