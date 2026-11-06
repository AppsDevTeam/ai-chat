<?php

declare(strict_types=1);

namespace ADT\AiChat\Mcp;

/**
 * Files an MCP tool made for the client (a report as xlsx, ...): the tool result
 * carries a short-lived link with a random token, and the client downloads it
 * without an Authorization header - the token is the permission. Only a hash of the
 * token is on disk; files are removed once expired (at the next store()).
 *
 * The route is yours, e.g. `mcp/download/<token>` matching {@see TOKEN_PATTERN},
 * serving {@see find()} as a file response.
 */
class DownloadStore
{
	/** Groups of 8: a solid hex string would read as a secret to filters. */
	public const TOKEN_PATTERN = '[0-9a-f]{8}(?:-[0-9a-f]{8}){5}';

	public function __construct(
		private readonly string $dir,
		private readonly int $ttlSeconds = 3600,
	) {
	}

	public function getTtlSeconds(): int
	{
		return $this->ttlSeconds;
	}

	/**
	 * Moves $sourceFile into the store under $filename and returns the token of its link.
	 */
	public function store(string $sourceFile, string $filename): string
	{
		$this->deleteExpired();

		$token = implode('-', str_split(bin2hex(random_bytes(24)), 8));
		$dir = $this->tokenDir($token);
		if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
			throw new \RuntimeException('Cannot create the download directory ' . $dir);
		}

		$target = $dir . '/' . self::sanitizeFilename($filename);
		if (!@rename($sourceFile, $target)) {
			if (!@copy($sourceFile, $target)) {
				throw new \RuntimeException('Cannot store the download ' . $target);
			}
			@unlink($sourceFile);
		}

		return $token;
	}

	/** Path of the file of a valid token, or null. */
	public function find(string $token): ?string
	{
		if (!preg_match('~^' . self::TOKEN_PATTERN . '$~', $token)) {
			return null;
		}

		$dir = $this->tokenDir($token);
		if (!is_dir($dir) || filemtime($dir) < time() - $this->ttlSeconds) {
			return null;
		}

		foreach (scandir($dir) ?: [] as $file) {
			if ($file !== '.' && $file !== '..' && is_file($dir . '/' . $file)) {
				return $dir . '/' . $file;
			}
		}

		return null;
	}

	private function tokenDir(string $token): string
	{
		return rtrim($this->dir, '/') . '/' . hash('sha256', $token);
	}

	private function deleteExpired(): void
	{
		foreach (glob(rtrim($this->dir, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (filemtime($dir) < time() - $this->ttlSeconds) {
				array_map('unlink', glob($dir . '/*') ?: []);
				@rmdir($dir);
			}
		}
	}

	private static function sanitizeFilename(string $filename): string
	{
		$filename = (string) preg_replace('/[^0-9A-Za-z._-]+/', '_', basename(str_replace('\\', '/', $filename)));

		return trim($filename, '._') !== '' ? $filename : 'download';
	}
}
