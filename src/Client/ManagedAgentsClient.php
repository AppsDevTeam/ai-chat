<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

/**
 * Transport to the Anthropic Managed Agents API (beta).
 *
 * The package deliberately ships no HTTP implementation: the API is in beta and
 * projects usually already have an HTTP client with their own logging and error
 * handling. Implement this interface over it - the methods map 1:1 to the REST
 * endpoints and both take and return plain decoded JSON arrays.
 *
 * Once an official SDK supports these endpoints, an adapter over it can replace
 * the hand-rolled implementation without touching anything else in this package.
 */
interface ManagedAgentsClient
{
	/**
	 * POST /agents - creates an agent with a model, system prompt and custom tools.
	 *
	 * @param list<array<string, mixed>> $tools custom tool definitions ({type:custom, ...})
	 * @return array<string, mixed> response body (contains id and version)
	 */
	public function createAgent(string $name, string $systemPrompt, array $tools): array;

	/**
	 * POST /agents/{id} - updates an EXISTING agent (same id, new version), e.g.
	 * after the database structure and thus the system prompt changed.
	 *
	 * @param list<array<string, mixed>> $tools
	 * @return array<string, mixed>
	 */
	public function updateAgent(string $agentId, string $systemPrompt, array $tools): array;

	/**
	 * POST /environments - creates the environment a session runs in.
	 *
	 * @return array<string, mixed> response body (contains id)
	 */
	public function createEnvironment(string $name): array;

	/**
	 * POST /sessions - starts a new session for the given agent and environment.
	 *
	 * @return array<string, mixed> response body (contains id and status)
	 */
	public function createSession(string $agentId, string $environmentId, ?string $title = null): array;

	/**
	 * POST /sessions/{id}/events - sends events (user.message, user.custom_tool_result, ...).
	 *
	 * @param list<array<string, mixed>> $events
	 * @return array<string, mixed>
	 */
	public function sendSessionEvents(string $sessionId, array $events): array;

	/**
	 * POST /files - uploads a file (multipart/form-data, field "file") so it can be
	 * referenced from a user.message content block ({@see \ADT\AiChat\Attachment}).
	 * Send the anthropic-beta headers for both the Files API and Managed Agents.
	 *
	 * @return array<string, mixed> response body (contains id, filename, mime_type)
	 */
	public function uploadFile(string $filename, string $contents, string $mediaType): array;

	/**
	 * GET /sessions/{id}/events - lists the events of a session (polling).
	 *
	 * @return array<string, mixed> response body with a "data" key
	 */
	public function listSessionEvents(string $sessionId, int $limit = 1000, ?int $page = null): array;
}
