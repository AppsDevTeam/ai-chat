<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use ADT\DoctrineAnonymization\Attributes\Description;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Columns of an {@see McpToken}: name, token_hash (unique), expires_at, last_used_at.
 * The id, the owner association ({@see McpToken::getTokenOwner()}) and the creation
 * time stay in your entity. Leave the table out of the anonymized views
 * (AnonymizationPolicy excludedEntities).
 */
trait McpTokenTrait
{
	#[ORM\Column(name: 'name', type: 'string', nullable: false)]
	#[Description('Name of the token given by its owner (e.g. the client it is used in).')]
	protected string $name;

	/** SHA-256 hash of the token (McpTokenGenerator::hash()), the readable token is shown once on creation. */
	#[ORM\Column(name: 'token_hash', type: 'string', unique: true, nullable: true)]
	#[Anonymize(AnonymizationType::SECRET)]
	#[Description('SHA-256 hash of the token; the readable token is never stored.')]
	protected ?string $tokenHash = null;

	#[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
	#[Description('Expiration of the token; null = does not expire.')]
	protected ?DateTimeImmutable $expiresAt = null;

	#[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
	#[Description('Last successful request with the token (updated at most once a minute).')]
	protected ?DateTimeImmutable $lastUsedAt = null;

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$this->name = $name;
		return $this;
	}

	public function getTokenHash(): ?string
	{
		return $this->tokenHash;
	}

	public function setTokenHash(?string $tokenHash): static
	{
		$this->tokenHash = $tokenHash;
		return $this;
	}

	public function getExpiresAt(): ?DateTimeImmutable
	{
		return $this->expiresAt;
	}

	public function setExpiresAt(?DateTimeImmutable $expiresAt): static
	{
		$this->expiresAt = $expiresAt;
		return $this;
	}

	public function getLastUsedAt(): ?DateTimeImmutable
	{
		return $this->lastUsedAt;
	}

	public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static
	{
		$this->lastUsedAt = $lastUsedAt;
		return $this;
	}

	public function isExpired(?DateTimeImmutable $now = null): bool
	{
		return $this->expiresAt !== null && $this->expiresAt <= ($now ?? new DateTimeImmutable());
	}
}
