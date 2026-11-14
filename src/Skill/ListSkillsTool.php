<?php

declare(strict_types=1);

namespace ADT\AiChat\Skill;

use ADT\AiChat\Tool\Tool;

/**
 * `list_skills` - names, titles and "when to use" of the skills the user may use.
 * Put it together with {@see GetSkillTool} into the CompositeToolExecutor.
 */
final class ListSkillsTool implements Tool
{
	public const NAME = 'list_skills';

	public const DEFAULT_DESCRIPTION = 'List the skills available to the current user. A skill is a step-by-step guide '
		. 'for a task that a SQL query alone cannot do - e.g. a file with a list of specific records, or an action '
		. 'in the system. Call it before answering whenever the user asks you to DO or PRODUCE something (a file, '
		. 'a list of specific people, an action), and whenever the user asks HOW to use the system, where to find '
		. 'something or what to do when something does not work; then load the matching skill with get_skill and '
		. 'follow it exactly. If no skill matches, continue normally.';

	/**
	 * @param string $description what the model reads about the tool - a project adds e.g. which
	 *                            of its tools do an action by themselves and need no skill
	 */
	public function __construct(
		private readonly SkillProvider $provider,
		private readonly string $description = self::DEFAULT_DESCRIPTION,
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
			'description' => $this->description,
			'input_schema' => [
				'type' => 'object',
				'properties' => new \stdClass(),
				'required' => [],
			],
		];
	}

	public function handle(array $input): array
	{
		$skills = [];
		foreach ($this->provider->getSkills() as $skill) {
			$skills[] = [
				'name' => $skill->name,
				'title' => $skill->title,
				'description' => $skill->description,
			];
		}

		return ['skills' => $skills];
	}
}
