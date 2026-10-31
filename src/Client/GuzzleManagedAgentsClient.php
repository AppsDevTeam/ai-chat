<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

use ADT\AiChat\Exception\ApiUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Default transport over Guzzle (`composer require guzzlehttp/guzzle`).
 *
 * The Managed Agents API is a beta, so every request carries the `anthropic-beta`
 * header on top of the standard `x-api-key` + `anthropic-version` pair. Failures
 * surface as {@see ApiUnavailableException} with a short, user-safe detail (HTTP
 * status) - the Guzzle exception with the full response is the previous one.
 *
 * Extend it for request logging, metrics or retries: {@see request()} and
 * {@see createHttpClient()} are the hooks.
 */
class GuzzleManagedAgentsClient implements ManagedAgentsClient
{
	public const MANAGED_AGENTS_BETA = 'managed-agents-2026-04-01';
	public const FILES_API_BETA = 'files-api-2025-04-14';

	private ?ClientInterface $client = null;

	/**
	 * @param string|ApiKeyProvider $apiKey a fixed key, or a provider asked on every
	 *        request (a key per tenant, changeable at runtime)
	 * @param float $timeout seconds per request; file uploads and long event lists
	 *        need more than the Guzzle default
	 */
	public function __construct(
		protected readonly string|ApiKeyProvider $apiKey,
		protected readonly string $model,
		protected readonly string $baseUrl = 'https://api.anthropic.com/v1/',
		protected readonly string $anthropicVersion = '2023-06-01',
		protected readonly float $timeout = 300,
		protected readonly float $connectTimeout = 10,
	) {
	}

	public function createAgent(string $name, string $systemPrompt, array $tools, ?array $multiagent = null): array
	{
		return $this->request('POST', 'agents', [
			'name' => $name,
			'model' => $this->model,
			'system' => $systemPrompt,
			'tools' => $tools,
		] + ($multiagent !== null ? ['multiagent' => $multiagent] : []));
	}

	public function updateAgent(string $agentId, string $systemPrompt, array $tools, ?array $multiagent = null): array
	{
		// The update endpoint uses an optimistic lock - the current version must be
		// read first and sent along.
		$current = $this->request('GET', 'agents/' . rawurlencode($agentId));

		return $this->request('POST', 'agents/' . rawurlencode($agentId), [
			'version' => $current['version'] ?? null,
			'model' => $this->model,
			'system' => $systemPrompt,
			'tools' => $tools,
			'multiagent' => $multiagent,
		]);
	}

	public function createEnvironment(string $name): array
	{
		// A session requires an environment even when only custom tools are used.
		return $this->request('POST', 'environments', [
			'name' => $name,
			'config' => [
				'type' => 'cloud',
				// Consider restricting networking when you add built-in tools.
				'networking' => ['type' => 'unrestricted'],
			],
		]);
	}

	public function createSession(string $agentId, string $environmentId, ?string $title = null, ?array $model = null): array
	{
		$body = [
			'agent' => $model !== null ? ['type' => 'agent_with_overrides', 'id' => $agentId, 'model' => $model] : $agentId,
			'environment_id' => $environmentId,
		];

		// The title comes from the user message; the API refuses control and format
		// characters in it.
		$title = $title !== null ? trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $title)) : '';
		if ($title !== '') {
			$body['title'] = $title;
		}

		return $this->request('POST', 'sessions', $body);
	}

	public function retrieveSession(string $sessionId): array
	{
		return $this->request('GET', 'sessions/' . rawurlencode($sessionId));
	}

	public function sendSessionEvents(string $sessionId, array $events): array
	{
		return $this->request('POST', 'sessions/' . rawurlencode($sessionId) . '/events', [
			'events' => $events,
		]);
	}

	public function listSessionEvents(string $sessionId, int $limit = 1000, ?string $page = null, ?string $order = null): array
	{
		$query = ['limit' => $limit];
		if ($page !== null) {
			$query['page'] = $page;
		}
		if ($order !== null) {
			$query['order'] = $order;
		}

		return $this->request('GET', 'sessions/' . rawurlencode($sessionId) . '/events', null, $query);
	}

	public function uploadFile(string $filename, string $contents, string $mediaType): array
	{
		// No Content-Type here: Guzzle sets multipart/form-data with the boundary
		// itself, a JSON one would make the API miss the "file" field.
		return $this->send('POST', 'files', [
			'headers' => ['anthropic-beta' => self::FILES_API_BETA . ',' . self::MANAGED_AGENTS_BETA],
			'multipart' => [[
				'name' => 'file',
				'contents' => $contents,
				'filename' => $filename,
				'headers' => ['Content-Type' => $mediaType],
			]],
		]);
	}

	/**
	 * A JSON request to the Managed Agents API.
	 *
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 * @throws ApiUnavailableException
	 */
	protected function request(string $method, string $path, ?array $body = null, array $query = []): array
	{
		$options = ['headers' => ['anthropic-beta' => self::MANAGED_AGENTS_BETA]];

		if ($body !== null) {
			$options['json'] = $body;
		}

		if ($query) {
			$options['query'] = $query;
		}

		return $this->send($method, $path, $options);
	}

	/**
	 * @param array<string, mixed> $options Guzzle request options
	 * @return array<string, mixed>
	 * @throws ApiUnavailableException
	 */
	protected function send(string $method, string $path, array $options): array
	{
		// Per request, not in the client defaults - the HTTP client is reused while
		// the key from a provider may change.
		$options['headers']['x-api-key'] = $this->getApiKey();

		try {
			$response = $this->getHttpClient()->request($method, rtrim($this->baseUrl, '/') . '/' . $path, $options);

			return (array) json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
		} catch (GuzzleException $e) {
			throw ApiUnavailableException::fromDetail($this->describeError($e), $e);
		} catch (\JsonException $e) {
			throw ApiUnavailableException::fromDetail('invalid response', $e);
		}
	}

	protected function createHttpClient(): ClientInterface
	{
		return new Client([
			'headers' => [
				'anthropic-version' => $this->anthropicVersion,
			],
			'timeout' => $this->timeout,
			'connect_timeout' => $this->connectTimeout,
		]);
	}

	protected function getApiKey(): string
	{
		return $this->apiKey instanceof ApiKeyProvider ? $this->apiKey->getApiKey() : $this->apiKey;
	}

	/**
	 * A short description for the user-facing message - no URL, body or headers.
	 */
	protected function describeError(GuzzleException $e): string
	{
		if ($e instanceof RequestException && $e->getResponse() !== null) {
			return 'HTTP ' . $e->getResponse()->getStatusCode();
		}

		return 'connection failed';
	}

	private function getHttpClient(): ClientInterface
	{
		return $this->client ??= $this->createHttpClient();
	}
}
