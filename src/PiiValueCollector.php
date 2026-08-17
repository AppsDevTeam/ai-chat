<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\DoctrineAnonymization\PersonalDataColumns;

/**
 * Collects the concrete values that appeared in personal-data columns of the SQL
 * results of a turn, so that {@see PiiResponseFilter} can remove them from the
 * final reply even where a regex cannot recognise them (names, birth dates).
 *
 * Matching is by column name in the query RESULT ({@see PersonalDataColumns}
 * guarantees those names are masked everywhere, so no legitimate readable value
 * can be collected by mistake).
 */
class PiiValueCollector
{
	public function __construct(
		private readonly PersonalDataColumns $personalDataColumns,
	) {
	}

	/**
	 * @param list<array{tool_name: string, tool_input: array<string, mixed>, tool_result: array<string, mixed>}> $toolData
	 * @return list<string>
	 */
	public function collect(array $toolData): array
	{
		$piiColumns = $this->personalDataColumns->getDirectIdentifierColumns();
		if (!$piiColumns) {
			return [];
		}

		$values = [];
		foreach ($toolData as $entry) {
			if (($entry['tool_name'] ?? '') !== 'execute_sql') {
				continue;
			}

			foreach ($entry['tool_result']['result'] ?? [] as $row) {
				if (!is_array($row)) {
					continue;
				}

				foreach ($row as $column => $value) {
					if (isset($piiColumns[strtolower((string) $column)]) && is_scalar($value)) {
						$values[(string) $value] = true;
					}
				}
			}
		}

		return array_keys($values);
	}
}
