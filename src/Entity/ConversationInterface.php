<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

/**
 * A conversation between one of your users and the agent.
 *
 * Implement it on your entity with {@see ConversationTrait}; the owner relation
 * (your User entity) and anything else project specific stays on your side.
 * {@see \ADT\AiChat\ChatService} creates conversations with `new $class()`, so
 * keep the constructor free of required arguments.
 */
interface ConversationInterface
{
	public const RATING_UP = 1;
	public const RATING_DOWN = -1;

	public function getId(): int|string|null;

	public function getTitle(): string;

	public function setTitle(string $title): static;

	/** Managed Agents session id; null or '' when no session exists yet. */
	public function getSessionId(): ?string;

	public function setSessionId(?string $sessionId): static;

	public function isDeleted(): bool;

	public function setDeleted(bool $deleted): static;

	public function getCreated(): \DateTimeImmutable;

	public function setCreated(\DateTimeImmutable $created): static;

	public function getUpdated(): \DateTimeImmutable;

	public function setUpdated(\DateTimeImmutable $updated): static;

	/** {@see self::RATING_UP}, {@see self::RATING_DOWN} or null when not rated. */
	public function getRating(): ?int;

	public function setRating(?int $rating): static;

	public function getRatingComment(): ?string;

	public function setRatingComment(?string $ratingComment): static;

	public function getRated(): ?\DateTimeImmutable;

	public function setRated(?\DateTimeImmutable $rated): static;
}
