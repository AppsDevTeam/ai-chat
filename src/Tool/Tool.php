<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

/**
 * One project tool of the agent, put together with the built-in ones by
 * {@see CompositeToolExecutor}.
 *
 * The tool runs in the queue worker (chat) or in the MCP request - see
 * {@see \ADT\AiChat\ChatService::setUserScope()} for running it as the user who asked.
 */
interface Tool
{
	/** Unique tool name the model calls (snake_case). */
	public function getName(): string;

	/**
	 * Definition without the `type` key: {name, description, input_schema}.
	 *
	 * @return array<string, mixed>
	 */
	public function getDefinition(): array;

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> result sent back to the model; an "error" key marks
	 *                              it as failed
	 */
	public function handle(array $input): array;
}
