<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * The whole `/mcp` endpoint: HTTP method, bearer token ({@see McpTokenAuthenticator}),
 * then {@see McpServer} - run as the token owner ({@see McpTokenPolicy::runAs()}). Your
 * controller maps the request on {@see handle()} and the {@see McpResponse} back.
 */
class McpEndpoint
{
	public function __construct(
		private readonly McpServer $server,
		private readonly McpTokenAuthenticator $authenticator,
	) {
	}

	/**
	 * @param ?string $authorization the Authorization header
	 * @param array<string, mixed> $logContext merged into every log record
	 */
	public function handle(string $httpMethod, ?string $authorization, string $body, array $logContext = []): McpResponse
	{
		$httpMethod = strtoupper($httpMethod);
		if (!in_array($httpMethod, ['POST', 'DELETE'], true)) {
			// GET (SSE stream) is not offered; the answer reveals nothing, no need to authenticate
			return McpResponse::methodNotAllowed();
		}

		try {
			$token = $this->authenticator->authenticate($authorization);
		} catch (McpTokenException $e) {
			return $e->toResponse();
		}

		$owner = $token->getTokenOwner();
		$logContext += ['ownerId' => self::ownerId($owner), 'mcpTokenId' => $token->getId()];

		return $this->authenticator->getPolicy()->runAs(
			$owner,
			fn(): McpResponse => $this->server->handle($httpMethod, $body, $logContext),
		);
	}

	private static function ownerId(mixed $owner): int|string|null
	{
		if (is_object($owner) && method_exists($owner, 'getId')) {
			return $owner->getId();
		}

		return is_object($owner) && isset($owner->id) ? $owner->id : (is_scalar($owner) ? $owner : null);
	}
}
