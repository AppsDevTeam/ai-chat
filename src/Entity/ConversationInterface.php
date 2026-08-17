<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

/**
 * A conversation between one of your users and the agent.
 *
 * Implement it on your entity with {@see ConversationTrait}; the owner relation
 * (your User entity) and anything else project specific stays on your side.
 */
interface ConversationInterface
{
	public function getId(): int|string|null;

	public function getTitle(): string;

	public function setTitle(string $title): static;

	/** Managed Agents session id; null or '' when no session exists yet. */
	public function getSessionId(): ?string;

	public function setSessionId(?string $sessionId): static;

	public function isDeleted(): bool;

	public function setDeleted(bool $deleted): static;

	public function getCreated(): \DateTimeImmutable;

	public function getUpdated(): \DateTimeImmutable;

	public function setUpdated(\DateTimeImmutable $updated): static;
}
