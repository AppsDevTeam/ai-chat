<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks the bearer token of an MCP client (or of any external AI calling your API with
 * the same personal tokens): the token exists and has not expired, its owner may sign
 * in and is allowed ({@see McpTokenPolicy}). Records the last use at most once a minute.
 */
class McpTokenAuthenticator
{
	/** How often at most lastUsedAt is written - not every request of the client. */
	public const LAST_USED_RESOLUTION_SECONDS = 60;

	/**
	 * @param class-string<McpToken> $tokenClass your token entity
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly string $tokenClass,
		private readonly McpTokenPolicy $policy,
	) {
	}

	public function getPolicy(): McpTokenPolicy
	{
		return $this->policy;
	}

	/**
	 * @param ?string $authorization the Authorization header
	 * @throws McpTokenException
	 */
	public function authenticate(?string $authorization): McpToken
	{
		$raw = self::parseBearer($authorization) ?? throw new McpTokenException(McpTokenException::MISSING);

		// The repository, not a query object - those filter by the signed-in user, and
		// nobody is signed in here.
		$token = $this->em->getRepository($this->tokenClass)->findOneBy(['tokenHash' => McpTokenGenerator::hash($raw)]);
		if (!$token instanceof McpToken || $token->isExpired()) {
			throw new McpTokenException(McpTokenException::INVALID);
		}

		$owner = $token->getTokenOwner();
		if ($owner === null || !$this->policy->canSignIn($owner)) {
			throw new McpTokenException(McpTokenException::CANNOT_SIGN_IN);
		}
		if (!$this->policy->isAllowed($owner)) {
			throw new McpTokenException(McpTokenException::FORBIDDEN);
		}

		$this->touch($token);

		return $token;
	}

	private function touch(McpToken $token): void
	{
		$now = new DateTimeImmutable();
		$lastUsed = $token->getLastUsedAt();
		if ($lastUsed !== null && $now->getTimestamp() - $lastUsed->getTimestamp() < self::LAST_USED_RESOLUTION_SECONDS) {
			return;
		}

		$token->setLastUsedAt($now);
		$this->em->flush();
	}

	private static function parseBearer(?string $header): ?string
	{
		if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
			return null;
		}

		return $m[1];
	}
}
