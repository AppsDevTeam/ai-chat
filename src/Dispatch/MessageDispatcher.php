<?php

declare(strict_types=1);

namespace ADT\AiChat\Dispatch;

/**
 * Hands a user message over to background processing.
 *
 * An agent turn takes minutes (polling, tool round-trips), so it must never run
 * inside a web request. The package does not care how the hand-off happens -
 * implement this with your queue of choice (RabbitMQ, database queue, ...); the
 * worker then calls your service which runs the turn via {@see \ADT\AiChat\AgentTurnRunner}.
 *
 * A synchronous implementation (running the turn immediately) is fine for tests
 * and CLI tools, just not for web requests.
 */
interface MessageDispatcher
{
	/**
	 * @param list<array{fileId: string, mediaType: string, filename: string}> $attachments
	 *        files already uploaded to the Files API, to be replayed as
	 *        {@see \ADT\AiChat\Attachment} objects in the worker
	 * @param string|null $context goes to the AI after the message (e.g. the record open
	 *        in the application) - pass it to processQueuedMessage() as is
	 */
	public function dispatch(int|string $conversationId, string $userMessage, array $attachments = [], ?string $context = null): void;
}
