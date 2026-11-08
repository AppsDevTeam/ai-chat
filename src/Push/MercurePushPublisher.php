<?php

declare(strict_types=1);

namespace ADT\AiChat\Push;

use ADT\AiChat\Entity\ConversationInterface;
use Psr\Log\LoggerInterface;

/**
 * {@see ChatPushPublisher} over a Mercure hub (https://mercure.rocks, built into
 * FrankenPHP and Caddy). Every conversation is a private topic: the widget gets a
 * subscriber token (JWT, HS256) for its conversation only, the worker publishes with
 * the publisher key. The update carries just the conversation id.
 */
class MercurePushPublisher implements ChatPushPublisher
{
	/**
	 * @param string $publishUrl the hub as the worker reaches it (often an internal address)
	 * @param string $subscribeUrl the hub as the browser reaches it (same origin as the app
	 *        avoids CORS, e.g. `/.well-known/mercure` proxied to the hub)
	 * @param string $topicPrefix tells apart the applications (tenants) sharing one hub -
	 *        the conversation ids of two tenants overlap
	 * @param string|null $issuer `iss` of the tokens (a hub with an `issuer` block wants it)
	 * @param string|null $audience `aud` of the tokens - the resource identifier of the hub
	 */
	public function __construct(
		private readonly string $publishUrl,
		private readonly string $subscribeUrl,
		#[\SensitiveParameter]
		private readonly string $publisherKey,
		#[\SensitiveParameter]
		private readonly string $subscriberKey,
		private readonly string $topicPrefix = 'ai-chat',
		private readonly int $tokenTtlSeconds = 3600,
		private readonly float $timeoutSeconds = 1.0,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?string $issuer = null,
		private readonly ?string $audience = null,
	) {
	}

	public function conversationChanged(ConversationInterface $conversation): void
	{
		$topic = $this->topic($conversation);
		$body = http_build_query([
			'topic' => $topic,
			'data' => json_encode(['conversation' => $conversation->getId()], JSON_THROW_ON_ERROR),
			'private' => 'on',
		]);
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => implode("\r\n", [
				'Authorization: Bearer ' . $this->jwt(self::grant('publish', $topic) + ['exp' => time() + 60], $this->publisherKey),
				'Content-Type: application/x-www-form-urlencoded',
			]),
			'content' => $body,
			'timeout' => $this->timeoutSeconds,
			'ignore_errors' => true,
		]]);

		$response = @file_get_contents($this->publishUrl, false, $context);
		// $http_response_header is deprecated since PHP 8.5
		$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
		$status = (string) ($headers[0] ?? '');
		if ($response === false || !str_contains($status, ' 200')) {
			// The widget still polls - a lost push only delays the update
			$this->logger?->warning(sprintf('Mercure: update of %s not published (%s).', $topic, $status !== '' ? $status : 'no response'));
		}
	}

	public function subscription(ConversationInterface $conversation): ?array
	{
		$topic = $this->topic($conversation);

		return [
			'url' => $this->subscribeUrl,
			'topic' => $topic,
			'token' => $this->jwt(self::grant('subscribe', $topic) + ['exp' => time() + $this->tokenTtlSeconds], $this->subscriberKey),
		];
	}

	private function topic(ConversationInterface $conversation): string
	{
		return $this->topicPrefix . '/conversation/' . $conversation->getId();
	}

	/**
	 * Permission for one topic - the claim of the current protocol (authorization_details,
	 * RFC 9396) and the older `mercure` claim, so the token works with a hub in either mode
	 *
	 * @return array<string, mixed>
	 */
	private static function grant(string $action, string $topic): array
	{
		return [
			'authorization_details' => [[
				'type' => 'https://mercure.rocks/authorization-detail',
				'actions' => [$action],
				'topics' => [['match' => $topic]],
			]],
			'mercure' => [$action => [$topic]],
		];
	}

	/**
	 * An access token as RFC 9068 wants it (`typ: at+jwt`, iat, jti, sub, client_id) - a hub in
	 * the modern mode refuses other JWTs, an older one does not mind the extra claims.
	 *
	 * @param array<string, mixed> $claims
	 */
	private function jwt(array $claims, string $key): string
	{
		$claims += array_filter(['iss' => $this->issuer, 'aud' => $this->audience], static fn(?string $value): bool => $value !== null) + [
			'sub' => 'ai-chat',
			'client_id' => 'ai-chat',
			'iat' => time(),
			'jti' => bin2hex(random_bytes(16)),
		];
		$encode = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
		$unsigned = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'at+jwt'])) . '.' . $encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

		return $unsigned . '.' . $encode(hash_hmac('sha256', $unsigned, $key, true));
	}
}
