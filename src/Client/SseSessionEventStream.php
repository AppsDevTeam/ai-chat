<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

/**
 * Server-sent events read from a PHP stream resource (an HTTP response body).
 *
 * Each read waits for the next line at most for the time left of its timeout
 * (stream_set_timeout), so a worker can hold the stream and still check whether
 * the turn was cancelled in between. A line cut by the timeout is kept and
 * completed by the next read. Only `data:` lines matter - the event type is in the
 * JSON itself; comments (keep-alives) and other fields are skipped.
 */
class SseSessionEventStream implements SessionEventStream
{
	/** After the first event only this long is waited for more - a burst is read whole, a single event is not delayed */
	private const BURST_SECONDS = 0.01;

	private string $line = '';

	private string $data = '';

	private bool $closed = false;

	/**
	 * @param resource $handle
	 */
	public function __construct(private $handle)
	{
	}

	public function read(float $timeoutSeconds): array
	{
		if ($this->closed) {
			throw new \RuntimeException('The event stream is closed.');
		}

		$events = [];
		$deadline = microtime(true) + $timeoutSeconds;
		while (true) {
			$remaining = $deadline - microtime(true);
			if ($events) {
				$remaining = min($remaining, self::BURST_SECONDS);
			}
			if ($remaining <= 0) {
				break;
			}

			stream_set_timeout($this->handle, (int) $remaining, (int) (($remaining - floor($remaining)) * 1_000_000));
			$chunk = fgets($this->handle);
			if ($chunk === false || $chunk === '') {
				$meta = stream_get_meta_data($this->handle);
				if ($meta['timed_out'] ?? false) {
					break;
				}
				// The server ended the stream (a session can also close it after a while)
				$this->close();
				if ($events) {
					break;
				}
				throw new \RuntimeException('The event stream was closed by the server.');
			}

			$this->line .= $chunk;
			if (!str_ends_with($this->line, "\n")) {
				continue;
			}
			$line = rtrim($this->line, "\r\n");
			$this->line = '';

			$event = $this->processLine($line);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}

	public function close(): void
	{
		if (!$this->closed) {
			$this->closed = true;
			fclose($this->handle);
		}
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * @return array<string, mixed>|null the event a blank line completed
	 */
	private function processLine(string $line): ?array
	{
		if ($line === '') {
			$data = $this->data;
			$this->data = '';
			if ($data === '') {
				return null;
			}
			$event = json_decode($data, true);

			return is_array($event) && isset($event['type']) ? $event : null;
		}

		if (str_starts_with($line, 'data:')) {
			$value = substr($line, 5);
			$this->data .= ($this->data !== '' ? "\n" : '') . (str_starts_with($value, ' ') ? substr($value, 1) : $value);
		}

		return null;
	}
}
