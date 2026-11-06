<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * Readable tokens and their stored hash. Only the hash goes to the database; the
 * readable token is shown to its owner once.
 */
final class McpTokenGenerator
{
	public const LENGTH = 32;

	private const CHARS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/** A random token of {@see LENGTH} alphanumeric characters, with an optional prefix. */
	public static function generate(string $prefix = ''): string
	{
		$token = '';
		for ($i = 0; $i < self::LENGTH; $i++) {
			$token .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
		}

		return $prefix . $token;
	}

	/** SHA-256 hex - the value of the token_hash column. */
	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}
}
