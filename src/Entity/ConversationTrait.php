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
 * readable in the anonymized views. The rating comment is free text too.
 *
 * The turn* columns describe the running turn (heartbeat, progress, a stop request
 * of the user) - the worker writes them, the widget polls them.
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

	#[ORM\Column(type: 'smallint', nullable: true)]
	#[Description('User rating of the conversation: 1 = helpful (thumbs up), -1 = not helpful (thumbs down), null = not rated.')]
	protected ?int $rating = null;

	#[ORM\Column(type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('Optional comment the user added to the rating of the conversation.')]
	protected ?string $ratingComment = null;

	#[ORM\Column(nullable: true)]
	#[Description('Date and time when the user last rated the conversation; null when not rated.')]
	protected ?\DateTimeImmutable $rated = null;

	#[ORM\Column(name: 'turn_activity', nullable: true)]
	#[Description('Heartbeat of the running assistant turn - when the session last produced an event; null when no turn ran since the last message.')]
	protected ?\DateTimeImmutable $turnActivity = null;

	#[ORM\Column(name: 'turn_progress', type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('JSON progress of the running assistant turn: tool calls so far and the subagents with their task and status.')]
	protected ?string $turnProgress = null;

	#[ORM\Column(name: 'turn_cancelled', nullable: true)]
	#[Description('Date and time when the user stopped the running assistant turn; null when not stopped.')]
	protected ?\DateTimeImmutable $turnCancelled = null;

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

	public function setCreated(\DateTimeImmutable $created): static
	{
		$this->created = $created;
		return $this;
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

	public function getRating(): ?int
	{
		return $this->rating;
	}

	public function setRating(?int $rating): static
	{
		$this->rating = $rating;
		return $this;
	}

	public function getRatingComment(): ?string
	{
		return $this->ratingComment;
	}

	public function setRatingComment(?string $ratingComment): static
	{
		$this->ratingComment = $ratingComment;
		return $this;
	}

	public function getRated(): ?\DateTimeImmutable
	{
		return $this->rated;
	}

	public function setRated(?\DateTimeImmutable $rated): static
	{
		$this->rated = $rated;
		return $this;
	}

	public function getTurnActivity(): ?\DateTimeImmutable
	{
		return $this->turnActivity;
	}

	public function setTurnActivity(?\DateTimeImmutable $turnActivity): static
	{
		$this->turnActivity = $turnActivity;
		return $this;
	}

	/**
	 * @return array{tool_calls: int, threads: list<array{id: string, name: string, status: string, task: string, tool_calls: int}>}|null
	 */
	public function getTurnProgress(): ?array
	{
		$decoded = $this->turnProgress !== null ? json_decode($this->turnProgress, true) : null;

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param array{tool_calls: int, threads: list<array{id: string, name: string, status: string, task: string, tool_calls: int}>}|null $turnProgress
	 */
	public function setTurnProgress(?array $turnProgress): static
	{
		$this->turnProgress = $turnProgress !== null ? json_encode($turnProgress, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
		return $this;
	}

	public function getTurnCancelled(): ?\DateTimeImmutable
	{
		return $this->turnCancelled;
	}

	public function setTurnCancelled(?\DateTimeImmutable $turnCancelled): static
	{
		$this->turnCancelled = $turnCancelled;
		return $this;
	}
}
