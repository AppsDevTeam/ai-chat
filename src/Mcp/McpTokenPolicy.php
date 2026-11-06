<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * Your rules for the owner of a valid token ({@see McpTokenAuthenticator}): may they
 * sign in, may they use external AI, and how to run the request as them.
 */
interface McpTokenPolicy
{
	/** The same rules as a sign-in (active account, ...); false = 401. */
	public function canSignIn(mixed $owner): bool;

	/** The permission to use the MCP server / external AI; false = 403. */
	public function isAllowed(mixed $owner): bool;

	/**
	 * Runs the request as the owner - sign them in statelessly (in memory only) when
	 * project tools or permission-scoped queries need it, otherwise just call $handle.
	 *
	 * @template T
	 * @param callable(): T $handle
	 * @return T
	 */
	public function runAs(mixed $owner, callable $handle): mixed;
}
