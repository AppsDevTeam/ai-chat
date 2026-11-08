<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

/**
 * An open event stream of a session ({@see SessionEventStreamer}).
 */
interface SessionEventStream
{
	/**
	 * Events that arrive within $timeoutSeconds, oldest first; an empty list when
	 * nothing came. Returns as soon as some events are there (a burst is read whole),
	 * so the caller can react right away and still check cancellation between reads.
	 *
	 * @return list<array<string, mixed>> decoded events (the same shape as the event list)
	 * @throws \RuntimeException the stream is closed or broken
	 */
	public function read(float $timeoutSeconds): array;

	public function close(): void;
}
