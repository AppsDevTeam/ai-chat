<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * The HTTP response {@see McpServer::handle()} asks the caller to send: a status
 * code, an optional JSON body and headers. Framework neutral on purpose - map it
 * onto a Nette/Symfony/PSR-7 response.
 */
final class McpResponse
{
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?string $body = null,
		public readonly array $headers = [],
	) {
	}

	/**
	 * @param array<mixed> $payload
	 */
	public static function json(array $payload, int $status = 200): self
	{
		return new self(
			$status,
			json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
			['Content-Type' => 'application/json'],
		);
	}

	/**
	 * Accepted notification / response - no body.
	 */
	public static function accepted(): self
	{
		return new self(202);
	}

	public static function methodNotAllowed(): self
	{
		return new self(405, null, ['Allow' => 'POST']);
	}

	/**
	 * Missing or invalid credentials. The body is a JSON-RPC error without an id, so
	 * clients that print it show a readable reason.
	 */
	public static function unauthorized(string $message = 'Unauthorized', string $realm = 'mcp'): self
	{
		$response = self::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => McpServer::ERROR_INVALID_REQUEST, 'message' => $message]], 401);

		return new self(401, $response->body, $response->headers + ['WWW-Authenticate' => sprintf('Bearer realm="%s"', $realm)]);
	}

	/**
	 * Authenticated, but not allowed to use the server.
	 */
	public static function forbidden(string $message = 'Forbidden'): self
	{
		return self::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => McpServer::ERROR_INVALID_REQUEST, 'message' => $message]], 403);
	}
}
