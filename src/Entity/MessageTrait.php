<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use ADT\DoctrineAnonymization\Attributes\Description;
use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see MessageInterface}. Add your own id and the relation to
 * your conversation entity:
 *
 *   #[ORM\ManyToOne(targetEntity: AiConversation::class)]
 *   #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
 *   protected ConversationInterface $conversation;
 *
 * The transcript, the tool calls and the attachment names may contain anything the
 * user typed or the queries returned, so all of them are anonymized as free text.
 * Tool data and attachments are JSON in text columns: they are only ever read as a
 * whole together with the message.
 */
trait MessageTrait
{
	#[ORM\Column(length: 16, options: ['default' => MessageInterface::AUTHOR_USER])]
	#[Description("Message author: 'user' (question of the user) or 'ai' (reply of the assistant).")]
	protected string $author = MessageInterface::AUTHOR_USER;

	#[ORM\Column(type: 'text')]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('Message text; for the assistant the redacted Markdown reply or the error text.')]
	protected string $content = '';

	#[ORM\Column(name: 'tool_data', type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('JSON list of the tool calls of the turn (name, input incl. SQL, result).')]
	protected ?string $toolData = null;

	#[ORM\Column(type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	#[Description('JSON list of the files attached to the user message (Files API id, media type, file name, size).')]
	protected ?string $attachments = null;

	#[ORM\Column(name: 'tokens_input', nullable: true)]
	#[Description('Context window size used by the last model request of the turn (input incl. cache tokens).')]
	protected ?int $tokensInput = null;

	#[ORM\Column(name: 'tokens_output', nullable: true)]
	#[Description('Total output tokens of the turn.')]
	protected ?int $tokensOutput = null;

	#[ORM\Column(length: 64, nullable: true)]
	#[Description('Claude model ID the conversation ran on for this message (it can be switched within a conversation); null means the model of the agent.')]
	protected ?string $model = null;

	#[ORM\Column(length: 16, nullable: true)]
	#[Description('Thinking effort level the conversation ran on for this message; null means the model default.')]
	protected ?string $effort = null;

	#[ORM\Column(options: ['default' => false])]
	#[Description('True when the assistant turn failed and the content holds the error text.')]
	protected bool $error = false;

	#[ORM\Column]
	#[Description('Date and time when the message was created.')]
	protected \DateTimeImmutable $created;

	public function getConversation(): ConversationInterface
	{
		return $this->conversation;
	}

	public function setConversation(ConversationInterface $conversation): static
	{
		$this->conversation = $conversation;
		return $this;
	}

	public function getAuthor(): string
	{
		return $this->author;
	}

	public function setAuthor(string $author): static
	{
		$this->author = $author;
		return $this;
	}

	public function getContent(): string
	{
		return $this->content;
	}

	public function setContent(string $content): static
	{
		$this->content = $content;
		return $this;
	}

	public function getToolData(): ?array
	{
		return self::decodeJsonList($this->toolData);
	}

	public function setToolData(?array $toolData): static
	{
		$this->toolData = self::encodeJsonList($toolData);
		return $this;
	}

	public function getAttachments(): ?array
	{
		return self::decodeJsonList($this->attachments);
	}

	public function setAttachments(?array $attachments): static
	{
		$this->attachments = self::encodeJsonList($attachments);
		return $this;
	}

	public function getTokensInput(): ?int
	{
		return $this->tokensInput;
	}

	public function setTokensInput(?int $tokensInput): static
	{
		$this->tokensInput = $tokensInput;
		return $this;
	}

	public function getTokensOutput(): ?int
	{
		return $this->tokensOutput;
	}

	public function setTokensOutput(?int $tokensOutput): static
	{
		$this->tokensOutput = $tokensOutput;
		return $this;
	}

	public function getModel(): ?string
	{
		return $this->model;
	}

	public function setModel(?string $model): static
	{
		$this->model = $model;
		return $this;
	}

	public function getEffort(): ?string
	{
		return $this->effort;
	}

	public function setEffort(?string $effort): static
	{
		$this->effort = $effort;
		return $this;
	}

	public function isError(): bool
	{
		return $this->error;
	}

	public function setError(bool $error): static
	{
		$this->error = $error;
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

	/**
	 * @param list<array<string, mixed>>|null $value
	 */
	private static function encodeJsonList(?array $value): ?string
	{
		return $value ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
	}

	/**
	 * A broken value reads as "nothing" - it must not take the whole transcript down.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private static function decodeJsonList(?string $value): ?array
	{
		if ($value === null || $value === '') {
			return null;
		}

		$decoded = json_decode($value, true);

		return is_array($decoded) ? array_values($decoded) : null;
	}
}
