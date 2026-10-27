<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

use ADT\AiChat\PiiResponseFilter;
use ADT\AiChat\ToolExecutor;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Model Context Protocol server over the read-only tools of the chat, so that
 * other AI agents (Claude Code, Claude Desktop, claude.ai connectors, ...) can
 * query the same anonymized data the chat works with.
 *
 * Transport: Streamable HTTP in its stateless form - every JSON-RPC message comes
 * in a POST and is answered with a single `application/json` response (no SSE, no
 * `Mcp-Session-Id`). Framework neutral like {@see \ADT\AiChat\Http\ChatEndpoint}:
 * {@see handle()} returns an {@see McpResponse} the caller maps onto its framework.
 *
 * Authentication and authorization are the caller's job - call {@see handle()}
 * only for an authenticated client that is allowed to read the data (see
 * {@see McpResponse::unauthorized()}).
 *
 * Only data tools are exposed (schema + SQL by default): rendering and export
 * tools make sense in the chat widget only. Every string in a tool result passes
 * through {@see PiiResponseFilter} before it leaves the server - the anonymized
 * views are the primary protection, this is defence in depth.
 */
class McpServer
{
	/** Newest first; the first one is offered when the client asks for an unknown version. */
	public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

	public const DEFAULT_TOOLS = ['get_database_schema', 'execute_sql'];

	public const DEFAULT_INSTRUCTIONS = <<<'TXT'
		Read-only access to an anonymized copy of the application database (MySQL/MariaDB).
		Start with get_database_schema without arguments to list the tables, then call it with
		table_name to see the columns and their descriptions. Query with execute_sql - only
		SELECT, SHOW and DESCRIBE are allowed and the number of returned rows is capped, so
		aggregate in SQL (COUNT, SUM, GROUP BY) instead of fetching raw rows.
		Personal data is masked in the views; never try to re-identify people.
		TXT;

	public const ERROR_PARSE = -32700;
	public const ERROR_INVALID_REQUEST = -32600;
	public const ERROR_METHOD_NOT_FOUND = -32601;
	public const ERROR_INVALID_PARAMS = -32602;
	public const ERROR_INTERNAL = -32603;

	/**
	 * @param list<string> $exposedTools names of the executor tools to publish
	 * @param ?string $instructions shown to the client model on initialize; null = none
	 */
	public function __construct(
		private readonly ToolExecutor $tools,
		private readonly ?PiiResponseFilter $responseFilter = new PiiResponseFilter(),
		private readonly string $serverName = 'adt-ai-chat',
		private readonly string $serverVersion = '1.0.0',
		private readonly ?string $instructions = self::DEFAULT_INSTRUCTIONS,
		private readonly array $exposedTools = self::DEFAULT_TOOLS,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * Handles one HTTP request of the Streamable HTTP transport.
	 *
	 * @param string $httpMethod request method (POST carries messages; GET would open
	 *                           an SSE stream, which this stateless server does not offer)
	 * @param array<string, mixed> $logContext merged into every log record (e.g. the user id)
	 */
	public function handle(string $httpMethod, string $body, array $logContext = []): McpResponse
	{
		$httpMethod = strtoupper($httpMethod);
		if ($httpMethod === 'DELETE') {
			// Session termination - there is no session to end.
			return new McpResponse(204);
		}
		if ($httpMethod !== 'POST') {
			return McpResponse::methodNotAllowed();
		}

		try {
			$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return McpResponse::json(self::error(null, self::ERROR_PARSE, 'Parse error'), 400);
		}

		if (is_array($payload) && array_is_list($payload) && $payload !== []) {
			// JSON-RPC batch (protocol 2025-03-26).
			$responses = [];
			foreach ($payload as $message) {
				$response = $this->handleMessage($message, $logContext);
				if ($response !== null) {
					$responses[] = $response;
				}
			}

			return $responses ? McpResponse::json($responses) : McpResponse::accepted();
		}

		$response = $this->handleMessage($payload, $logContext);

		return $response !== null ? McpResponse::json($response) : McpResponse::accepted();
	}

	/**
	 * Handles one decoded JSON-RPC message; null for notifications and responses,
	 * which get no reply.
	 *
	 * @param array<string, mixed> $logContext
	 * @return ?array<string, mixed>
	 */
	public function handleMessage(mixed $message, array $logContext = []): ?array
	{
		if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
			return self::error(null, self::ERROR_INVALID_REQUEST, 'Invalid Request');
		}

		$hasId = array_key_exists('id', $message);
		$id = $message['id'] ?? null;
		if ($hasId && !is_string($id) && !is_int($id)) {
			return self::error(null, self::ERROR_INVALID_REQUEST, 'Invalid Request: id must be a string or an integer');
		}

		if (!isset($message['method'])) {
			// A response to a server request - we never send any, nothing to do.
			return null;
		}
		if (!is_string($message['method'])) {
			return self::error($id, self::ERROR_INVALID_REQUEST, 'Invalid Request: method must be a string');
		}

		$params = $message['params'] ?? [];
		if (!is_array($params)) {
			return $hasId ? self::error($id, self::ERROR_INVALID_PARAMS, 'Invalid params') : null;
		}

		if (!$hasId) {
			// Notifications (initialized, cancelled, ...) need no action from a stateless server.
			return null;
		}

		try {
			return match ($message['method']) {
				'initialize' => self::result($id, $this->initialize($params)),
				'ping' => self::result($id, new \stdClass()),
				'tools/list' => self::result($id, ['tools' => $this->listTools()]),
				'tools/call' => $this->callTool($id, $params, $logContext),
				default => self::error($id, self::ERROR_METHOD_NOT_FOUND, 'Method not found: ' . $message['method']),
			};
		} catch (Throwable $e) {
			$this->logger?->error('MCP request failed: ' . $e->getMessage(), ['exception' => $e, 'method' => $message['method']] + $logContext);

			return self::error($id, self::ERROR_INTERNAL, 'Internal error');
		}
	}

	/**
	 * Tool definitions in the MCP shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listTools(): array
	{
		$tools = [];
		foreach ($this->tools->getCustomToolDefinitions() as $definition) {
			if (!in_array($definition['name'] ?? null, $this->exposedTools, true)) {
				continue;
			}

			$tools[] = [
				'name' => $definition['name'],
				'description' => $definition['description'] ?? '',
				'inputSchema' => self::normalizeSchema($definition['input_schema'] ?? ['type' => 'object']),
				'annotations' => [
					'readOnlyHint' => true,
					'destructiveHint' => false,
					'idempotentHint' => true,
					'openWorldHint' => false,
				],
			];
		}

		return $tools;
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function initialize(array $params): array
	{
		$requested = $params['protocolVersion'] ?? null;

		$result = [
			'protocolVersion' => in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
				? $requested
				: self::SUPPORTED_PROTOCOL_VERSIONS[0],
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo' => ['name' => $this->serverName, 'version' => $this->serverVersion],
		];
		if ($this->instructions !== null && $this->instructions !== '') {
			$result['instructions'] = $this->instructions;
		}

		return $result;
	}

	/**
	 * Tool failures (bad SQL, unknown table) are results with `isError: true`, not
	 * protocol errors - the client model is supposed to read them and retry.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $logContext
	 * @return array<string, mixed>
	 */
	private function callTool(int|string $id, array $params, array $logContext): array
	{
		$name = $params['name'] ?? null;
		if (!is_string($name) || !in_array($name, $this->exposedTools, true)) {
			return self::error($id, self::ERROR_INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : ''));
		}

		$arguments = $params['arguments'] ?? [];
		if (!is_array($arguments)) {
			return self::error($id, self::ERROR_INVALID_PARAMS, 'Tool arguments must be an object');
		}

		$started = microtime(true);
		$result = $this->filterValue($this->tools->handleToolCall($name, $arguments));
		$isError = isset($result['error']);

		$this->logger?->info('MCP tool call: ' . $name, [
			'tool' => $name,
			'arguments' => $arguments,
			'isError' => $isError,
			'durationMs' => (int) round((microtime(true) - $started) * 1000),
		] + $logContext);

		$text = $isError
			? (string) (is_scalar($result['error']) ? $result['error'] : json_encode($result['error']))
			: json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

		return self::result($id, [
			'content' => [['type' => 'text', 'text' => $text]],
			'isError' => $isError,
		]);
	}

	private function filterValue(mixed $value): mixed
	{
		if ($this->responseFilter === null) {
			return $value;
		}
		if (is_string($value)) {
			return $this->responseFilter->filter($value);
		}
		if (is_array($value)) {
			return array_map($this->filterValue(...), $value);
		}

		return $value;
	}

	/**
	 * An empty PHP array encodes as `[]`, but JSON Schema needs objects for
	 * `properties` - fix that so strict clients accept the schema.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	private static function normalizeSchema(array $schema): array
	{
		if (isset($schema['properties']) && $schema['properties'] === []) {
			$schema['properties'] = new \stdClass();
		}
		if (isset($schema['required']) && $schema['required'] === []) {
			unset($schema['required']);
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result(int|string $id, mixed $result): array
	{
		return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error(int|string|null $id, int $code, string $message): array
	{
		return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
	}
}
