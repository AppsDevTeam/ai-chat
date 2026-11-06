<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

use ADT\AiChat\ToolExecutor;

/**
 * The built-in tools ({@see \ADT\AiChat\ToolHandler}) plus project tools: a project
 * writes only its {@see Tool} classes.
 *
 *   new CompositeToolExecutor($toolHandler, [new MonthlyReportTool(...)])
 *
 * Give it to ChatService and AgentProvisioner instead of the ToolHandler (the agent is
 * updated with the new tools on the next session) and/or to the McpServer.
 */
class CompositeToolExecutor implements ToolExecutor, ExportToolProvider
{
	/** @var array<string, Tool> */
	private array $tools = [];

	/**
	 * @param iterable<Tool> $tools
	 */
	public function __construct(
		private readonly ToolExecutor $base,
		iterable $tools = [],
	) {
		foreach ($tools as $tool) {
			$this->tools[$tool->getName()] = $tool;
		}
	}

	public function handleToolCall(string $toolName, array $input): array
	{
		return isset($this->tools[$toolName])
			? $this->tools[$toolName]->handle($input)
			: $this->base->handleToolCall($toolName, $input);
	}

	public function getCustomToolDefinitions(): array
	{
		$definitions = $this->base->getCustomToolDefinitions();
		foreach ($this->tools as $tool) {
			$definitions[] = ['type' => 'custom'] + $tool->getDefinition();
		}

		return $definitions;
	}

	public function findExportTool(string $name): ?ExportTool
	{
		$tool = $this->tools[$name] ?? null;
		if ($tool instanceof ExportTool) {
			return $tool;
		}

		return $this->base instanceof ExportToolProvider ? $this->base->findExportTool($name) : null;
	}
}
