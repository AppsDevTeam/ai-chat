<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

use ADT\AiChat\ToolExecutor;

/**
 * The built-in tools ({@see \ADT\AiChat\ToolHandler}) plus project tools: a project
 * writes only its {@see Tool} classes, or a {@see ToolProvider} for a set of them.
 *
 *   new CompositeToolExecutor($toolHandler, [new MonthlyReportTool(...)], [$endpointTools])
 *
 * Give it to ChatService and AgentProvisioner instead of the ToolHandler (the agent is
 * updated with the new tools on the next session) and/or to the McpServer.
 */
class CompositeToolExecutor implements ToolExecutor, ExportToolProvider
{
	/** @var array<string, Tool>|null */
	private ?array $tools = null;

	/** @var iterable<Tool> */
	private readonly iterable $toolList;

	/**
	 * @param iterable<Tool> $tools
	 * @param iterable<ToolProvider> $providers asked on the first use
	 */
	public function __construct(
		private readonly ToolExecutor $base,
		iterable $tools = [],
		private readonly iterable $providers = [],
	) {
		$this->toolList = $tools;
	}

	public function handleToolCall(string $toolName, array $input): array
	{
		$tools = $this->getTools();
		return isset($tools[$toolName])
			? $tools[$toolName]->handle($input)
			: $this->base->handleToolCall($toolName, $input);
	}

	public function getCustomToolDefinitions(): array
	{
		$definitions = $this->base->getCustomToolDefinitions();
		foreach ($this->getTools() as $tool) {
			$definitions[] = ['type' => 'custom'] + $tool->getDefinition();
		}

		return $definitions;
	}

	public function findExportTool(string $name): ?ExportTool
	{
		$tool = $this->getTools()[$name] ?? null;
		if ($tool instanceof ExportTool) {
			return $tool;
		}

		return $this->base instanceof ExportToolProvider ? $this->base->findExportTool($name) : null;
	}

	/** @return array<string, Tool> */
	private function getTools(): array
	{
		if ($this->tools === null) {
			$this->tools = [];
			foreach ($this->toolList as $tool) {
				$this->tools[$tool->getName()] = $tool;
			}
			foreach ($this->providers as $provider) {
				foreach ($provider->getTools() as $tool) {
					$this->tools[$tool->getName()] = $tool;
				}
			}
		}

		return $this->tools;
	}
}
