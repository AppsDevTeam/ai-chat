<?php

declare(strict_types=1);

use ADT\AiChat\Mcp\DownloadStore;

class DownloadStoreTest extends \Codeception\Test\Unit
{
	private string $dir;

	protected function _before(): void
	{
		$this->dir = sys_get_temp_dir() . '/ai-chat-downloads-' . bin2hex(random_bytes(4));
	}

	protected function _after(): void
	{
		foreach (glob($this->dir . '/*/*') ?: [] as $file) {
			unlink($file);
		}
		foreach (glob($this->dir . '/*') ?: [] as $dir) {
			rmdir($dir);
		}
		@rmdir($this->dir);
	}

	private function source(string $content): string
	{
		$file = tempnam(sys_get_temp_dir(), 'src');
		file_put_contents($file, $content);

		return $file;
	}

	public function testStoredFileIsFoundByItsToken(): void
	{
		$store = new DownloadStore($this->dir);
		$token = $store->store($this->source('xlsx'), '../Report 10/2026.xlsx');

		$this->assertMatchesRegularExpression('~^' . DownloadStore::TOKEN_PATTERN . '$~', $token);
		$file = $store->find($token);
		$this->assertSame('2026.xlsx', basename($file));
		$this->assertSame('xlsx', file_get_contents($file));
		// only the hash of the token is on disk
		$this->assertStringNotContainsString($token, $file);
	}

	public function testUnknownMalformedOrExpiredTokenFindsNothing(): void
	{
		$store = new DownloadStore($this->dir, ttlSeconds: 60);
		$token = $store->store($this->source('x'), 'a.txt');

		$this->assertNull($store->find('00000000-00000000-00000000-00000000-00000000-00000000'));
		$this->assertNull($store->find('../../etc/passwd'));

		touch(dirname($store->find($token)), time() - 120);
		$this->assertNull($store->find($token));

		// an expired file is removed at the next store()
		$store->store($this->source('y'), 'b.txt');
		$this->assertCount(1, glob($this->dir . '/*'));
	}
}
