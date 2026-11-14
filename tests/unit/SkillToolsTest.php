<?php

declare(strict_types=1);

use ADT\AiChat\Skill\GetSkillTool;
use ADT\AiChat\Skill\ListSkillsTool;
use ADT\AiChat\Skill\Skill;
use ADT\AiChat\Skill\SkillProvider;
use ADT\AiChat\Tool\CompositeToolExecutor;
use ADT\AiChat\ToolHandler;
use ADT\AiChat\Tests\Support\StubQueryExecutor;

class SkillToolsTest extends \Codeception\Test\Unit
{
	private function provider(): SkillProvider
	{
		return new class implements SkillProvider {
			/** @var array<string, Skill> skills the user may use */
			private array $skills;

			public function __construct()
			{
				$this->skills = [
					'export' => new Skill('export', 'Export', 'User wants a file.', "1. Call export\n2. Done"),
					'booking' => new Skill('booking', 'Booking', 'User wants an appointment.', 'Find a slot.'),
				];
			}

			public function getSkills(): iterable
			{
				return array_values($this->skills);
			}

			public function getSkill(string $name): ?Skill
			{
				return $this->skills[$name] ?? null;
			}
		};
	}

	public function testListReturnsNamesAndDescriptionsWithoutInstructions(): void
	{
		$result = (new ListSkillsTool($this->provider()))->handle([]);

		$this->assertSame([
			['name' => 'export', 'title' => 'Export', 'description' => 'User wants a file.'],
			['name' => 'booking', 'title' => 'Booking', 'description' => 'User wants an appointment.'],
		], $result['skills']);
	}

	public function testGetReturnsInstructions(): void
	{
		$result = (new GetSkillTool($this->provider()))->handle(['name' => 'export']);

		$this->assertSame(['name' => 'export', 'title' => 'Export', 'instructions' => "1. Call export\n2. Done"], $result);
	}

	public function testUnknownOrForbiddenSkillIsAnError(): void
	{
		$tool = new GetSkillTool($this->provider());

		$this->assertStringContainsString('"secret" does not exist', $tool->handle(['name' => 'secret'])['error']);
		$this->assertArrayHasKey('error', $tool->handle([]));
		$this->assertArrayHasKey('error', $tool->handle(['name' => ['export']]));
	}

	public function testDescriptionOfListCanBeReplaced(): void
	{
		$this->assertSame(ListSkillsTool::DEFAULT_DESCRIPTION, (new ListSkillsTool($this->provider()))->getDefinition()['description']);
		$this->assertSame('Custom.', (new ListSkillsTool($this->provider(), 'Custom.'))->getDefinition()['description']);
	}

	public function testToolsPlugIntoCompositeExecutor(): void
	{
		$provider = $this->provider();
		$executor = new CompositeToolExecutor(new ToolHandler(new StubQueryExecutor()), [new ListSkillsTool($provider), new GetSkillTool($provider)]);

		$names = array_column($executor->getCustomToolDefinitions(), 'name');
		$this->assertContains('list_skills', $names);
		$this->assertContains('get_skill', $names);
		$this->assertSame('Find a slot.', $executor->handleToolCall('get_skill', ['name' => 'booking'])['instructions']);
	}
}
