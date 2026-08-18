<?php

declare(strict_types=1);

namespace App\AiChat;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Exception\AiChatException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Reference transport implementation over Guzzle (`composer require guzzlehttp/guzzle`).
 *
 * The Managed Agents API is a beta, so all requests carry the `anthropic-beta`
 * header on top of the standard `x-api-key` + `anthropic-version` pair. Copy this
 * class into your project and extend it with whatever your project needs -
 * request logging, retries, metrics.
 */
class GuzzleManagedAgentsClient implements ManagedAgentsClient
{
	private const MANAGED_AGENTS_BETA = 'managed-agents-2026-04-01';

	private ?Client $client = null;

	public function __construct(
		private readonly string $apiKey,
		private readonly string $model,
		private readonly string $baseUrl = 'https://api.anthropic.com/v1/',
		private readonly string $anthropicVersion = '2023-06-01',
	) {
	}

	public function createAgent(string $name, string $systemPrompt, array $tools): array
	{
		return $this->request('POST', 'agents', [
			'name' => $name,
			'model' => $this->model,
			'system' => $systemPrompt,
			'tools' => $tools,
		]);
	}

	public function updateAgent(string $agentId, string $systemPrompt, array $tools): array
	{
		// The update endpoint uses an optimistic lock - the current version must be
		// read first and sent along.
		$current = $this->request('GET', 'agents/' . rawurlencode($agentId));

		return $this->request('POST', 'agents/' . rawurlencode($agentId), [
			'version' => $current['version'] ?? null,
			'model' => $this->model,
			'system' => $systemPrompt,
			'tools' => $tools,
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

	public function createSession(string $agentId, string $environmentId, ?string $title = null): array
	{
		$body = [
			'agent' => $agentId,
			'environment_id' => $environmentId,
		];

		if ($title !== null && $title !== '') {
			$body['title'] = $title;
		}

		return $this->request('POST', 'sessions', $body);
	}

	public function sendSessionEvents(string $sessionId, array $events): array
	{
		return $this->request('POST', 'sessions/' . rawurlencode($sessionId) . '/events', [
			'events' => $events,
		]);
	}

	public function listSessionEvents(string $sessionId, int $limit = 1000, ?int $page = null): array
	{
		$query = ['limit' => $limit];
		if ($page !== null) {
			$query['page'] = $page;
		}

		return $this->request('GET', 'sessions/' . rawurlencode($sessionId) . '/events', null, $query);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $path, ?array $body = null, array $query = []): array
	{
		$options = ['headers' => ['anthropic-beta' => self::MANAGED_AGENTS_BETA]];

		if ($body !== null) {
			$options['json'] = $body;
		}

		if ($query) {
			$options['query'] = $query;
		}

		try {
			$response = $this->getClient()->request($method, rtrim($this->baseUrl, '/') . '/' . $path, $options);

			return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
		} catch (GuzzleException $e) {
			throw new AiChatException('Managed Agents API error: ' . $e->getMessage(), 0, $e);
		}
	}

	private function getClient(): Client
	{
		return $this->client ??= new Client([
			'headers' => [
				'Content-Type' => 'application/json',
				'x-api-key' => $this->apiKey,
				'anthropic-version' => $this->anthropicVersion,
			],
			'timeout' => 120,
		]);
	}
}
