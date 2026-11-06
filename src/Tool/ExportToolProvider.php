<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

/**
 * A {@see \ADT\AiChat\ToolExecutor} that knows the export tools behind the download
 * buttons ({@see CompositeToolExecutor} does).
 */
interface ExportToolProvider
{
	public function findExportTool(string $name): ?ExportTool;
}
