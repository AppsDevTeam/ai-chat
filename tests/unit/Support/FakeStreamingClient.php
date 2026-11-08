<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Client\SessionEventStream;
use ADT\AiChat\Client\SessionEventStreamer;
use ADT\AiChat\Exception\ApiUnavailableException;

/**
 * The fake client with the live event stream: every read of the stream releases the
 * next frame (the event list sees it too, as on the API).
 */
class FakeStreamingClient extends FakeManagedAgentsClient implements SessionEventStreamer
{
	public int $streamsOpened = 0;

	public bool $failOpen = false;

	/** The stream breaks on this read (1 = the first one); null = never */
	public ?int $breakOnRead = null;

	public function openSessionEventStream(string $sessionId): SessionEventStream
	{
		if ($this->failOpen) {
			throw ApiUnavailableException::fromDetail('HTTP 404');
		}
		$this->streamsOpened++;

		return new class ($this) implements SessionEventStream {
			private int $reads = 0;

			public function __construct(private readonly FakeStreamingClient $client)
			{
			}

			public function read(float $timeoutSeconds): array
			{
				if (++$this->reads === $this->client->breakOnRead) {
					throw new \RuntimeException('The event stream was closed by the server.');
				}

				return $this->client->releaseFrame();
			}

			public function close(): void
			{
			}
		};
	}
}
