<?php

declare(strict_types=1);

namespace ADT\AiChat\Skill;

/**
 * A step-by-step guide for a task the model cannot do with a single tool call - which
 * tools to call in which order and what to tell the user (e.g. "find the nearest free
 * slot, offer it, let the user confirm, then book it").
 *
 * The model sees the name, title and description of every skill the user may use
 * ({@see ListSkillsTool}) and loads the instructions only when it picks one
 * ({@see GetSkillTool}), so a long list of skills does not bloat every turn.
 */
final class Skill
{
	/**
	 * @param string $name identifier the model passes to get_skill (lowercase with dashes)
	 * @param string $description when to use the skill - the model picks skills by it
	 * @param string $instructions the guide itself (markdown)
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $title,
		public readonly string $description,
		public readonly string $instructions,
	) {
	}
}
