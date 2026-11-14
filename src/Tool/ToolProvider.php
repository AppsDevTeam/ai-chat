<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

/**
 * A set of {@see Tool}s that comes into being at runtime instead of one service per tool -
 * e.g. tools made from the endpoints of a project's API. {@see CompositeToolExecutor} asks
 * it on the first use.
 */
interface ToolProvider
{
	/** @return iterable<Tool> */
	public function getTools(): iterable;
}
