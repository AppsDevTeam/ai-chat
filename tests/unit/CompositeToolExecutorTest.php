<?php

declare(strict_types=1);

use ADT\AiChat\Http\Download;
use ADT\AiChat\Http\FileDownload;
use ADT\AiChat\Tool\CompositeToolExecutor;
use ADT\AiChat\Tool\ExportTool;
use ADT\AiChat\Tool\Tool;
use ADT\AiChat\Tool\ToolProvider;
use ADT\AiChat\ToolHandler;
use ADT\AiChat\Tests\Support\StubQueryExecutor;

class CompositeToolExecutorTest extends \Codeception\Test\Unit
{
	public static function reportTool(): ExportTool
	{
		return new class implements ExportTool {
			public function getName(): string
			{
				return 'monthly_report';
			}

			public function getDefinition(): array
			{
				return ['name' => 'monthly_report', 'description' => 'Report', 'input_schema' => ['type' => 'object', 'properties' => []]];
			}

			public function handle(array $input): array
			{
				return ['type' => 'export', 'token' => 'rep1', 'filename' => 'report.txt', 'total' => 42];
			}

			public function export(array $input, array $result): Download
			{
				$file = tempnam(sys_get_temp_dir(), 'rep');
				file_put_contents($file, 'month ' . ($input['month'] ?? '?'));

				return new FileDownload($file, $result['filename'], 'text/plain', deleteAfter: true);
			}
		};
	}

	private function executor(): CompositeToolExecutor
	{
		$plain = new class implements Tool {
			public function getName(): string
			{
				return 'ping';
			}

			public function getDefinition(): array
			{
				return ['name' => 'ping', 'description' => 'Ping', 'input_schema' => ['type' => 'object']];
			}

			public function handle(array $input): array
			{
				return ['pong' => true];
			}
		};

		return new CompositeToolExecutor(new ToolHandler(new StubQueryExecutor()), [self::reportTool(), $plain]);
	}

	public function testProjectToolsAreDeclaredAfterTheBuiltInOnes(): void
	{
		$definitions = $this->executor()->getCustomToolDefinitions();
		$names = array_column($definitions, 'name');

		$this->assertContains('execute_sql', $names);
		$this->assertSame(['monthly_report', 'ping'], array_slice($names, -2));
		$this->assertSame('custom', end($definitions)['type']);
	}

	public function testCallsAreRoutedToTheirTool(): void
	{
		$executor = $this->executor();

		$this->assertSame(['pong' => true], $executor->handleToolCall('ping', []));
		$this->assertSame(42, $executor->handleToolCall('monthly_report', [])['total']);
		$this->assertArrayHasKey('error', $executor->handleToolCall('nope', []));
	}

	public function testOnlyExportToolsAreFoundForDownloads(): void
	{
		$executor = $this->executor();

		$this->assertInstanceOf(ExportTool::class, $executor->findExportTool('monthly_report'));
		$this->assertNull($executor->findExportTool('ping'));
		$this->assertNull($executor->findExportTool('export_csv'));
	}

	public function testToolsOfProvidersAreAskedOnFirstUse(): void
	{
		$provider = new class implements ToolProvider {
			public int $calls = 0;

			public function getTools(): iterable
			{
				$this->calls++;
				yield CompositeToolExecutorTest::reportTool();
			}
		};
		$executor = new CompositeToolExecutor(new ToolHandler(new StubQueryExecutor()), [], [$provider]);
		$this->assertSame(0, $provider->calls);

		$this->assertContains('monthly_report', array_column($executor->getCustomToolDefinitions(), 'name'));
		$this->assertSame(42, $executor->handleToolCall('monthly_report', [])['total']);
		$this->assertSame(1, $provider->calls);
	}
}
