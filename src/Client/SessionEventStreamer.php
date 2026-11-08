<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

use ADT\AiChat\Exception\ApiUnavailableException;

/**
 * Optional capability of a {@see ManagedAgentsClient}: the live event stream of a
 * session (GET /sessions/{id}/events/stream, server-sent events).
 *
 * With it the turn runner gets every event the moment the session emits it instead
 * of polling the event list (up to {@see \ADT\AiChat\AiChatOptions::$pollIntervalMicroseconds}
 * later on every step of the turn). A client without it, or a stream that fails,
 * falls back to polling - nothing is lost, the runner keeps track of the events it saw.
 */
interface SessionEventStreamer
{
	/**
	 * Opens the stream. Only events emitted from now on come - there is no replay.
	 *
	 * @throws ApiUnavailableException the stream could not be opened (the runner polls instead)
	 */
	public function openSessionEventStream(string $sessionId): SessionEventStream;
}
