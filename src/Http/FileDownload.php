<?php

declare(strict_types=1);

namespace ADT\AiChat\Http;

/**
 * A file on disk as a {@see Download} - e.g. an xlsx an {@see \ADT\AiChat\Tool\ExportTool}
 * generated into a temporary file.
 */
final class FileDownload implements Download
{
	/**
	 * @param bool $deleteAfter remove the file once it has been streamed (temporary files)
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $filename,
		public readonly string $contentType = 'application/octet-stream',
		private readonly bool $deleteAfter = false,
	) {
	}

	public function getHeaders(): array
	{
		return self::attachmentHeaders($this->filename, $this->contentType) + ['Content-Length' => (string) filesize($this->path)];
	}

	public function stream($out = null): void
	{
		$close = $out === null;
		$out ??= fopen('php://output', 'w');
		$in = fopen($this->path, 'r');

		try {
			stream_copy_to_stream($in, $out);
		} finally {
			fclose($in);
			if ($close) {
				fclose($out);
			}
			if ($this->deleteAfter) {
				@unlink($this->path);
			}
		}
	}

	/**
	 * Headers of a file download with an ASCII fallback and the UTF-8 name (RFC 6266).
	 *
	 * @return array<string, string>
	 */
	public static function attachmentHeaders(string $filename, string $contentType): array
	{
		$filename = basename(str_replace('\\', '/', $filename));
		$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

		return [
			'Content-Type' => $contentType,
			'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
			'Cache-Control' => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		];
	}
}
