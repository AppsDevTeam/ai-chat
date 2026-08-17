<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\DoctrineAnonymization\Exception\QueryFailedException;
use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;

/**
 * Default tool set of the agent: schema introspection, read-only SQL over the
 * anonymized views, chart and table rendering, and a CSV export hand-off.
 *
 * All data access goes through {@see ReadOnlyQueryExecutor}, i.e. the dedicated
 * read-only account over the anonymized schema. Rendering tools only echo their
 * input back in a displayable shape - the frontend decides how to draw them.
 */
class ToolHandler implements ToolExecutor
{
	public function __construct(
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		private readonly bool $exportEnabled = true,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getToolDefinitions(): array
	{
		$tools = [
			[
				'name' => 'get_database_schema',
				'description' => 'Get the database schema. Without table_name returns list of all tables. With table_name returns columns of that table.',
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'table_name' => [
							'type' => 'string',
							'description' => 'Optional: specific table name to get columns for.',
						],
					],
					'required' => [],
				],
			],
			[
				'name' => 'execute_sql',
				'description' => 'Execute a read-only SQL SELECT query against the anonymized reporting database. Returns a limited number of rows as JSON. Only SELECT, SHOW, DESCRIBE are allowed.',
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'sql' => [
							'type' => 'string',
							'description' => 'The SQL SELECT query to execute.',
						],
					],
					'required' => ['sql'],
				],
			],
			[
				'name' => 'render_chart',
				'description' => 'Output a chart for the user. Use this to visualize data as bar, line, pie, or doughnut charts.',
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'chart_type' => [
							'type' => 'string',
							'enum' => ['bar', 'line', 'pie', 'doughnut'],
							'description' => 'Type of chart to render.',
						],
						'title' => [
							'type' => 'string',
							'description' => 'Chart title.',
						],
						'labels' => [
							'type' => 'array',
							'items' => ['type' => 'string'],
							'description' => 'Labels for the X axis or segments.',
						],
						'datasets' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'label' => ['type' => 'string'],
									'data' => [
										'type' => 'array',
										'items' => ['type' => 'number'],
									],
								],
								'required' => ['label', 'data'],
							],
							'description' => 'Data series to plot.',
						],
					],
					'required' => ['chart_type', 'labels', 'datasets'],
				],
			],
			[
				'name' => 'render_table',
				'description' => 'Output a formatted data table for the user.',
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'title' => [
							'type' => 'string',
							'description' => 'Optional table title.',
						],
						'headers' => [
							'type' => 'array',
							'items' => ['type' => 'string'],
							'description' => 'Column header labels.',
						],
						'rows' => [
							'type' => 'array',
							'items' => [
								'type' => 'array',
								'items' => ['type' => 'string'],
							],
							'description' => 'Table rows, each row is an array of cell values.',
						],
					],
					'required' => ['headers', 'rows'],
				],
			],
		];

		if ($this->exportEnabled) {
			$tools[] = [
				'name' => 'export_csv',
				'description' => 'Provide the user a downloadable CSV file with the FULL result set of a SQL query, without the row limit that execute_sql has. '
					. 'Use this instead of render_table whenever the user asks to export/download data, or when the result set is large (more than a few hundred rows). '
					. 'Do NOT also call execute_sql for the same data - just pass the SQL here; the rows are streamed straight into the file and are not returned to you.',
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'sql' => [
							'type' => 'string',
							'description' => 'The SQL SELECT query whose full result becomes the CSV. Same rules as execute_sql (read-only SELECT).',
						],
						'title' => [
							'type' => 'string',
							'description' => 'Optional human-readable title used as the download file name.',
						],
					],
					'required' => ['sql'],
				],
			];
		}

		return $tools;
	}

	public function getCustomToolDefinitions(): array
	{
		$custom = [];
		foreach ($this->getToolDefinitions() as $tool) {
			$custom[] = ['type' => 'custom'] + $tool;
		}

		return $custom;
	}

	public function handleToolCall(string $toolName, array $input): array
	{
		return match ($toolName) {
			'get_database_schema' => $this->handleGetSchema($input),
			'execute_sql' => $this->handleExecuteSql($input),
			'export_csv' => $this->handleExportCsv($input),
			'render_chart' => $this->handleRenderChart($input),
			'render_table' => $this->handleRenderTable($input),
			default => ['error' => 'Unknown tool: ' . $toolName],
		};
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function handleGetSchema(array $input): array
	{
		try {
			$table = $input['table_name'] ?? null;

			return ['result' => $table !== null
				? ['table' => $table, 'columns' => $this->queryExecutor->getTableColumns((string) $table)]
				: array_map(static fn(string $t): array => ['TABLE_NAME' => $t], $this->queryExecutor->getTables())];
		} catch (QueryNotAllowedException | QueryFailedException $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function handleExecuteSql(array $input): array
	{
		try {
			$rows = $this->queryExecutor->execute((string) ($input['sql'] ?? ''));

			return [
				'result' => $rows,
				'row_count' => count($rows),
			];
		} catch (QueryNotAllowedException | QueryFailedException $e) {
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Prepares a server-side CSV export: the SQL is only validated and stored (the
	 * caller persists the whole tool call), NOT executed - the rows are streamed
	 * only when the user actually downloads the file, so they never pass through
	 * the model. Returns a random token the frontend uses to request the download.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function handleExportCsv(array $input): array
	{
		$sql = (string) ($input['sql'] ?? '');

		try {
			// The same validation as execute_sql (read-only, anonymized schema only).
			$this->queryExecutor->validateQuery($sql);
		} catch (QueryNotAllowedException $e) {
			return ['error' => $e->getMessage()];
		}

		$title = trim((string) ($input['title'] ?? ''));
		$base = $title !== '' ? $title : 'export';
		$base = (string) preg_replace('/[^0-9A-Za-z_-]+/', '_', $base);
		$base = trim($base, '_') ?: 'export';

		return [
			'type' => 'export',
			'title' => $title,
			'filename' => $base . '.csv',
			// The SQL stays only in the persisted tool_input on the server; the
			// browser gets nothing but this token and the metadata above.
			'token' => bin2hex(random_bytes(8)),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function handleRenderChart(array $input): array
	{
		return [
			'type' => 'chart',
			'chart_type' => $input['chart_type'] ?? 'bar',
			'title' => $input['title'] ?? '',
			'labels' => $input['labels'] ?? [],
			'datasets' => $input['datasets'] ?? [],
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function handleRenderTable(array $input): array
	{
		return [
			'type' => 'table',
			'title' => $input['title'] ?? '',
			'headers' => $input['headers'] ?? [],
			'rows' => $input['rows'] ?? [],
		];
	}
}
