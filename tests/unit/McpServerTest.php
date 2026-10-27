<?php

declare(strict_types=1);

use ADT\AiChat\Mcp\McpResponse;
use ADT\AiChat\Mcp\McpServer;
use ADT\AiChat\Tests\Support\StubQueryExecutor;
use ADT\AiChat\ToolHandler;

class McpServerTest extends \Codeception\Test\Unit
{
	private StubQueryExecutor $executor;

	protected function _before(): void
	{
		$this->executor = StubQueryExecutor::withTables(['clients', 'orders']);
	}

	private function server(): McpServer
	{
		return new McpServer(new ToolHandler($this->executor), serverName: 'test-server', serverVersion: '9.9');
	}

	/**
	 * @param array<string, mixed> $message
	 * @return array<string, mixed>
	 */
	private function call(array $message): array
	{
		$response = $this->server()->handle('POST', json_encode($message));
		$this->assertSame(200, $response->status);
		$this->assertSame('application/json', $response->headers['Content-Type']);

		return json_decode($response->body, true);
	}

	public function testInitializeNegotiatesProtocolVersion(): void
	{
		$known = $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
		$this->assertSame(1, $known['id']);
		$this->assertSame('2025-03-26', $known['result']['protocolVersion']);
		$this->assertSame(['name' => 'test-server', 'version' => '9.9'], $known['result']['serverInfo']);
		$this->assertSame(['tools' => ['listChanged' => false]], $known['result']['capabilities']);
		$this->assertNotEmpty($known['result']['instructions']);

		$unknown = $this->call(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
		$this->assertSame(McpServer::SUPPORTED_PROTOCOL_VERSIONS[0], $unknown['result']['protocolVersion']);
	}

	public function testToolsListExposesOnlyDataTools(): void
	{
		$tools = $this->call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['result']['tools'];

		$this->assertSame(['get_database_schema', 'execute_sql'], array_column($tools, 'name'));
		$this->assertTrue($tools[0]['annotations']['readOnlyHint']);
		$this->assertSame('object', $tools[1]['inputSchema']['type']);
		$this->assertSame(['sql'], $tools[1]['inputSchema']['required']);
		// Empty "required" is dropped - some clients reject an empty list.
		$this->assertArrayNotHasKey('required', $tools[0]['inputSchema']);
	}

	public function testToolsCallReturnsJsonText(): void
	{
		$this->executor->rows = [['n' => 3]];

		$result = $this->call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'execute_sql', 'arguments' => ['sql' => 'SELECT 3 AS n']]])['result'];

		$this->assertFalse($result['isError']);
		$this->assertSame('text', $result['content'][0]['type']);
		$this->assertSame(['result' => [['n' => 3]], 'row_count' => 1], json_decode($result['content'][0]['text'], true));
		$this->assertSame(['SELECT 3 AS n'], $this->executor->executedSql);
	}

	public function testToolFailureIsAResultNotAProtocolError(): void
	{
		$result = $this->call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'execute_sql', 'arguments' => ['sql' => 'DROP TABLE x']]])['result'];

		$this->assertTrue($result['isError']);
		$this->assertStringContainsString('DROP', $result['content'][0]['text']);
	}

	public function testChatOnlyAndUnknownToolsAreRejected(): void
	{
		foreach (['export_csv', 'render_chart', 'nope'] as $tool) {
			$response = $this->call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
			$this->assertSame(McpServer::ERROR_INVALID_PARAMS, $response['error']['code'], $tool);
		}
	}

	public function testResultsAreRedacted(): void
	{
		$this->executor->rows = [['contact' => 'jan.novak@example.com', 'phone' => '+420 777 123 456', 'count' => 5]];

		$text = $this->call(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'execute_sql', 'arguments' => ['sql' => 'SELECT 1']]])['result']['content'][0]['text'];

		$this->assertStringNotContainsString('jan.novak', $text);
		$this->assertStringNotContainsString('777 123 456', $text);
		$this->assertSame(5, json_decode($text, true)['result'][0]['count']);
	}

	public function testNotificationsAndResponsesGetNoBody(): void
	{
		$notification = $this->server()->handle('POST', json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
		$this->assertSame(202, $notification->status);
		$this->assertNull($notification->body);

		$clientResponse = $this->server()->handle('POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]));
		$this->assertSame(202, $clientResponse->status);
	}

	public function testProtocolErrors(): void
	{
		$parse = $this->server()->handle('POST', '{nope');
		$this->assertSame(400, $parse->status);
		$this->assertSame(McpServer::ERROR_PARSE, json_decode($parse->body, true)['error']['code']);

		$this->assertSame(McpServer::ERROR_INVALID_REQUEST, $this->call(['id' => 1, 'method' => 'ping'])['error']['code']);
		$this->assertSame(McpServer::ERROR_METHOD_NOT_FOUND, $this->call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])['error']['code']);

		$ping = $this->server()->handle('POST', json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']));
		$this->assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', $ping->body);
	}

	public function testBatch(): void
	{
		$response = $this->server()->handle('POST', json_encode([
			['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
			['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
			['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
		]));

		$this->assertSame([1, 2], array_column(json_decode($response->body, true), 'id'));
	}

	public function testHttpMethods(): void
	{
		$get = $this->server()->handle('GET', '');
		$this->assertSame(405, $get->status);
		$this->assertSame('POST', $get->headers['Allow']);

		$this->assertSame(204, $this->server()->handle('DELETE', '')->status);
	}

	public function testUnauthorizedResponse(): void
	{
		$response = McpResponse::unauthorized('Invalid token');

		$this->assertSame(401, $response->status);
		$this->assertSame('Bearer realm="mcp"', $response->headers['WWW-Authenticate']);
		$this->assertSame('Invalid token', json_decode($response->body, true)['error']['message']);
	}
}
