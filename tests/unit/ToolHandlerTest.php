<?php

declare(strict_types=1);

use ADT\AiChat\Tests\Support\StubQueryExecutor;
use ADT\AiChat\ToolHandler;

class ToolHandlerTest extends \Codeception\Test\Unit
{
	private StubQueryExecutor $executor;

	protected function _before(): void
	{
		$this->executor = StubQueryExecutor::withTables(['clients', 'orders']);
	}

	private function handler(bool $exportEnabled = true): ToolHandler
	{
		return new ToolHandler($this->executor, $exportEnabled);
	}

	public function testSchemaToolListsTablesAndColumns(): void
	{
		$tables = $this->handler()->handleToolCall('get_database_schema', []);
		$this->assertSame([['TABLE_NAME' => 'clients'], ['TABLE_NAME' => 'orders']], $tables['result']);

		$columns = $this->handler()->handleToolCall('get_database_schema', ['table_name' => 'clients']);
		$this->assertSame('clients', $columns['result']['table']);

		$missing = $this->handler()->handleToolCall('get_database_schema', ['table_name' => 'nope']);
		$this->assertArrayHasKey('error', $missing);
	}

	public function testExecuteSqlReturnsRowsAndMapsErrors(): void
	{
		$this->executor->rows = [['n' => 1]];

		$ok = $this->handler()->handleToolCall('execute_sql', ['sql' => 'SELECT 1']);
		$this->assertSame(1, $ok['row_count']);

		$bad = $this->handler()->handleToolCall('execute_sql', ['sql' => 'DROP TABLE x']);
		$this->assertArrayHasKey('error', $bad);
	}

	public function testExportValidatesButDoesNotExecute(): void
	{
		$result = $this->handler()->handleToolCall('export_csv', ['sql' => 'SELECT 1', 'title' => 'All clients']);

		$this->assertSame('export', $result['type']);
		$this->assertSame('All_clients.csv', $result['filename']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $result['token']);
		// The SQL is only validated here - the rows are streamed at download time.
		$this->assertSame([], $this->executor->executedSql);
		$this->assertArrayNotHasKey('sql', $result);

		$rejected = $this->handler()->handleToolCall('export_csv', ['sql' => 'DROP TABLE x']);
		$this->assertArrayHasKey('error', $rejected);
	}

	public function testExportToolCanBeDisabled(): void
	{
		$names = array_column($this->handler(false)->getToolDefinitions(), 'name');

		$this->assertNotContains('export_csv', $names);
		$this->assertContains('execute_sql', $names);
	}

	public function testCustomDefinitionsWrapEveryTool(): void
	{
		foreach ($this->handler()->getCustomToolDefinitions() as $tool) {
			$this->assertSame('custom', $tool['type']);
			$this->assertArrayHasKey('input_schema', $tool);
		}
	}

	public function testUnknownToolIsAnError(): void
	{
		$this->assertArrayHasKey('error', $this->handler()->handleToolCall('nope', []));
	}
}
