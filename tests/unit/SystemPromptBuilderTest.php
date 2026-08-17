<?php

declare(strict_types=1);

use ADT\AiChat\AiChatOptions;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\SystemPromptBuilder;
use ADT\AiChat\Tests\Support\StubQueryExecutor;

class SystemPromptBuilderTest extends \Codeception\Test\Unit
{
	private function builder(array $tables, ?string $template = null, ?AiChatOptions $options = null): SystemPromptBuilder
	{
		$path = tempnam(sys_get_temp_dir(), 'prompt');
		file_put_contents($path, $template ?? "Intro\n{{tableList}}\nOutro");

		return new SystemPromptBuilder(
			StubQueryExecutor::withTables($tables),
			$path,
			$options ?? new AiChatOptions(),
		);
	}

	public function testTableListIsInjected(): void
	{
		$prompt = $this->builder(['clients', 'orders'])->build();

		$this->assertStringContainsString("- clients\n- orders", $prompt);
		$this->assertStringContainsString('Intro', $prompt);
		$this->assertStringContainsString('Outro', $prompt);
	}

	public function testEmptySchemaProducesAHint(): void
	{
		$this->assertStringContainsString('Schema is empty', $this->builder([])->build());
	}

	public function testOverlongListIsTruncatedWithAMarker(): void
	{
		$tables = array_map(static fn(int $i): string => 'table_' . $i, range(1, 500));
		// Tiny budget: template reserve + 1000 safety margin eat almost everything.
		$options = new AiChatOptions(systemPromptMaxLength: 1200);

		$prompt = $this->builder($tables, null, $options)->build();

		$this->assertStringContainsString('further tables omitted', $prompt);
		$this->assertLessThan(1200, mb_strlen($prompt));
	}

	public function testMissingTemplateFailsLoudly(): void
	{
		$builder = new SystemPromptBuilder(StubQueryExecutor::withTables([]), '/nonexistent/prompt.md');

		$this->expectException(AiChatException::class);
		$builder->build();
	}

	public function testDefaultTemplateExistsAndHasThePlaceholder(): void
	{
		$template = file_get_contents(SystemPromptBuilder::defaultTemplatePath());

		$this->assertStringContainsString(SystemPromptBuilder::TABLE_LIST_PLACEHOLDER, $template);
	}
}
