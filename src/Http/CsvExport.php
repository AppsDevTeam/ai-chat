<?php

declare(strict_types=1);

namespace ADT\AiChat\Http;

use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;

/**
 * A server-side CSV export ready to be streamed: the rows go straight from the
 * anonymized views to the response, never through the model.
 *
 * Send the headers ({@see getHeaders()}) first, then call {@see stream()} from a
 * streamed response. The query is validated before this object exists, so an
 * error cannot corrupt half a file - failures during streaming are written into
 * the file instead of producing a mysteriously empty one.
 */
final class CsvExport
{
	/** @var (callable(\Throwable): void)|null */
	private $onError;

	/**
	 * @param string $emptyText row written when the query returns nothing
	 * @param string $errorText prefix of the row written when streaming fails
	 * @param (callable(\Throwable): void)|null $onError for logging
	 */
	public function __construct(
		private readonly ReadOnlyQueryExecutor $queryExecutor,
		public readonly string $sql,
		public readonly string $filename,
		private readonly string $emptyText = 'The export contains no data.',
		private readonly string $errorText = 'Export failed',
		?callable $onError = null,
	) {
		$this->onError = $onError;
	}

	/**
	 * @return array<string, string>
	 */
	public function getHeaders(): array
	{
		$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->filename) ?: 'export.csv';

		return [
			'Content-Type' => 'text/csv; charset=UTF-8',
			'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($this->filename),
			'Cache-Control' => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		];
	}

	/**
	 * @param resource|null $out defaults to php://output
	 */
	public function stream($out = null): void
	{
		$close = $out === null;
		$out ??= fopen('php://output', 'w');

		// UTF-8 BOM so Excel renders diacritics.
		fwrite($out, "\xEF\xBB\xBF");

		$first = true;
		$count = 0;

		try {
			$this->queryExecutor->streamQuery($this->sql, static function (array $row) use (&$first, &$count, $out): void {
				if ($first) {
					fputcsv($out, array_keys($row), ',', '"', '');
					$first = false;
				}
				fputcsv($out, array_values($row), ',', '"', '');
				$count++;
			});

			if ($count === 0) {
				fputcsv($out, [$this->emptyText], ',', '"', '');
			}
		} catch (\Throwable $e) {
			if ($this->onError !== null) {
				($this->onError)($e);
			}
			fputcsv($out, [$this->errorText . ': ' . $e->getMessage()], ',', '"', '');
		}

		if ($close) {
			fclose($out);
		}
	}
}
