<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

use DateTimeImmutable;

/**
 * A personal access token of an MCP client (Claude Code, Claude Desktop, ...) - the
 * client acts as the owner of the token. Only a SHA-256 hash of the token is stored
 * ({@see McpTokenGenerator}); {@see McpTokenTrait} maps the columns.
 */
interface McpToken
{
	/**
	 * Without a return type on purpose: entity interfaces often declare an untyped
	 * getId() and an interface extending both would not compile otherwise.
	 *
	 * @return int|string|null
	 */
	public function getId();

	/** The user the client acts as (your identity / user entity). */
	public function getTokenOwner(): mixed;

	public function getTokenHash(): ?string;

	public function getExpiresAt(): ?DateTimeImmutable;

	public function getLastUsedAt(): ?DateTimeImmutable;

	public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static;

	public function isExpired(?DateTimeImmutable $now = null): bool;
}
