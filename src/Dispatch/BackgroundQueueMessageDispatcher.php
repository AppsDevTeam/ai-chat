<?php

declare(strict_types=1);

namespace ADT\AiChat\Dispatch;

use ADT\BackgroundQueue\BackgroundQueue;

/**
 * Hand-off over adt/background-queue (`composer require adt/background-queue`).
 *
 * The other half of the wiring maps the callback back to
 * {@see \ADT\AiChat\ChatService::processQueuedMessage()}:
 *
 *   backgroundQueue:
 *       callbacks:
 *           aiChatProcessMessage:
 *               callback: [@ADT\AiChat\ChatService, processQueuedMessage]
 *               # with a broker, use a dedicated queue: a turn takes minutes and
 *               # must not block e-mails or other jobs sharing the default queue
 *               queue: %backgroundQueue.queue%-ai-chat
 */
class BackgroundQueueMessageDispatcher implements MessageDispatcher
{
	public const DEFAULT_CALLBACK = 'aiChatProcessMessage';

	public function __construct(
		private readonly BackgroundQueue $queue,
		private readonly string $callbackName = self::DEFAULT_CALLBACK,
	) {
	}

	public function dispatch(int|string $conversationId, string $userMessage, array $attachments = []): void
	{
		$this->queue->publish($this->callbackName, [
			'conversationId' => $conversationId,
			'userMessage' => $userMessage,
			'attachments' => $attachments,
		]);
	}
}
