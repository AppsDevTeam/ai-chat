<?php

declare(strict_types=1);

namespace ADT\AiChat\Skill;

/**
 * Where the skills come from (a database table, files, ...) and who may use them.
 *
 * Both methods answer for the user the tool runs as (the queue worker / MCP request runs
 * as the user who asked, see {@see \ADT\AiChat\ChatService::setUserScope()}). A skill the
 * user may not use must look exactly like a missing one.
 */
interface SkillProvider
{
	/**
	 * Active skills the current user may use.
	 *
	 * @return iterable<Skill>
	 */
	public function getSkills(): iterable;

	/** The skill, or null when it does not exist, is inactive or the user may not use it. */
	public function getSkill(string $name): ?Skill;
}
