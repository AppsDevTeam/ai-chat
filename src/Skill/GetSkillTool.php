<?php

declare(strict_types=1);

namespace ADT\AiChat\Skill;

use ADT\AiChat\Tool\Tool;

/** `get_skill` - the full instructions of a skill, loaded only when the model uses it. */
final class GetSkillTool implements Tool
{
	public const NAME = 'get_skill';

	public function __construct(
		private readonly SkillProvider $provider,
	) {
	}

	public function getName(): string
	{
		return self::NAME;
	}

	public function getDefinition(): array
	{
		return [
			'name' => self::NAME,
			'description' => 'Load the full instructions of a skill returned by ' . ListSkillsTool::NAME . '. '
				. 'Follow them step by step - they say which tools to call and what to tell the user.',
			'input_schema' => [
				'type' => 'object',
				'properties' => [
					'name' => [
						'type' => 'string',
						'description' => 'Skill name exactly as returned by ' . ListSkillsTool::NAME . '.',
					],
				],
				'required' => ['name'],
			],
		];
	}

	public function handle(array $input): array
	{
		$name = is_string($input['name'] ?? null) ? $input['name'] : '';
		$skill = $name !== '' ? $this->provider->getSkill($name) : null;
		if ($skill === null) {
			return ['error' => sprintf('Skill "%s" does not exist. Use %s to see the available ones.', $name, ListSkillsTool::NAME)];
		}

		return [
			'name' => $skill->name,
			'title' => $skill->title,
			'instructions' => $skill->instructions,
		];
	}
}
