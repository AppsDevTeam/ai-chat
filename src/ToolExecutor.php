<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Executes one custom tool call made by the agent.
 *
 * {@see ToolHandler} is the default implementation (SQL over the anonymized
 * schema, charts, tables, CSV export); implement this interface directly when a
 * project needs a different tool set.
 */
interface ToolExecutor
{
	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> result to send back to the agent; an "error"
	 *                              key marks it as failed
	 */
	public function handleToolCall(string $toolName, array $input): array;

	/**
	 * Tool definitions in the Managed Agents "custom tool" shape
	 * ({type: custom, name, description, input_schema}), declared on the agent at
	 * provisioning time.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getCustomToolDefinitions(): array;
}
