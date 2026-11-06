<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * A refused token: {@see $status} 401 (missing, unknown or expired token, owner cannot
 * sign in) or 403 (owner not allowed). {@see $reason} tells which, for your own messages.
 */
final class McpTokenException extends \RuntimeException
{
	public const MISSING = 'missing';
	public const INVALID = 'invalid';
	public const CANNOT_SIGN_IN = 'cannotSignIn';
	public const FORBIDDEN = 'forbidden';

	private const MESSAGES = [
		self::MISSING => 'Missing bearer token',
		self::INVALID => 'Invalid or expired token',
		self::CANNOT_SIGN_IN => 'The token owner cannot sign in',
		self::FORBIDDEN => 'The token owner is not allowed to use the MCP server',
	];

	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct(self::MESSAGES[$reason], $reason === self::FORBIDDEN ? 403 : 401);
	}

	public function getStatus(): int
	{
		return $this->getCode();
	}

	public function toResponse(): McpResponse
	{
		return $this->getStatus() === 403
			? McpResponse::forbidden($this->getMessage())
			: McpResponse::unauthorized($this->getMessage());
	}
}
