<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

/**
 * One message of a conversation - a user question or an agent reply.
 *
 * Implement it on your entity with {@see MessageTrait}; the entity adds the id
 * and the relation to its conversation entity (field `conversation` by default,
 * see {@see \ADT\AiChat\ChatOptions::$conversationField}). {@see \ADT\AiChat\ChatService}
 * creates messages with `new $class()`, so keep the constructor free of required
 * arguments.
 */
interface MessageInterface
{
	public const AUTHOR_USER = 'user';
	public const AUTHOR_AI = 'ai';

	public function getId(): int|string|null;

	public function getConversation(): ConversationInterface;

	public function setConversation(ConversationInterface $conversation): static;

	/** {@see AUTHOR_USER} or {@see AUTHOR_AI}. */
	public function getAuthor(): string;

	public function setAuthor(string $author): static;

	public function getContent(): string;

	public function setContent(string $content): static;

	/**
	 * Every tool call of the turn ({@see \ADT\AiChat\TurnResult::$toolData}); never
	 * send it to the browser as is - it holds the SQL.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public function getToolData(): ?array;

	/**
	 * @param list<array<string, mixed>>|null $toolData
	 */
	public function setToolData(?array $toolData): static;

	/**
	 * Files attached to a user message.
	 *
	 * @return list<array{fileId: string, mediaType: string, filename: string, size?: int}>|null
	 */
	public function getAttachments(): ?array;

	/**
	 * @param list<array{fileId: string, mediaType: string, filename: string, size?: int}>|null $attachments
	 */
	public function setAttachments(?array $attachments): static;

	/**
	 * The user's question as the AI received it - with anchors instead of personal
	 * data ({@see \ADT\AiChat\Pseudonymization\Pseudonymizer}); set on the reply,
	 * null when nothing was replaced.
	 */
	public function getSentContent(): ?string;

	public function setSentContent(?string $sentContent): static;

	public function getTokensInput(): ?int;

	public function setTokensInput(?int $tokensInput): static;

	public function getTokensOutput(): ?int;

	public function setTokensOutput(?int $tokensOutput): static;

	/** The reply is an error text (the turn failed), not an answer. */
	public function isError(): bool;

	public function setError(bool $error): static;

	public function getCreated(): \DateTimeImmutable;

	public function setCreated(\DateTimeImmutable $created): static;
}
