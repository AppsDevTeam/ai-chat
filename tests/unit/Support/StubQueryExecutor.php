<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\DoctrineAnonymization\Exception\QueryFailedException;
use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;

/**
 * In-memory stand-in for {@see ReadOnlyQueryExecutor}. Overrides every public
 * method, so the parent constructor (and any database) is never touched.
 */
class StubQueryExecutor extends ReadOnlyQueryExecutor
{
	/** @var list<string> */
	public array $tables = [];

	/** @var list<array<string, mixed>> */
	public array $rows = [];

	/** @var list<string> */
	public array $executedSql = [];

	public function __construct()
	{
		// Deliberately NOT calling the parent constructor - no database in tests.
	}

	/**
	 * @param list<string> $tables
	 */
	public static function withTables(array $tables): self
	{
		$stub = new self();
		$stub->tables = $tables;

		return $stub;
	}

	public function validateQuery(string $sql): void
	{
		if (stripos($sql, 'DROP') !== false) {
			throw new QueryNotAllowedException('Forbidden keyword detected: DROP');
		}
	}

	public function execute(string $sql): array
	{
		$this->validateQuery($sql);
		$this->executedSql[] = $sql;

		return $this->rows;
	}

	public function streamQuery(string $sql, callable $onRow): int
	{
		$this->validateQuery($sql);
		foreach ($this->rows as $i => $row) {
			$onRow($row, $i);
		}

		return count($this->rows);
	}

	public function getTables(): array
	{
		return $this->tables;
	}

	public function getTableColumns(string $table): array
	{
		if (!in_array($table, $this->tables, true)) {
			throw new QueryFailedException(sprintf('Table "%s" not found.', $table));
		}

		return [['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_COMMENT' => '']];
	}

	public function getSchemaName(): string
	{
		return 'test_anon';
	}
}
