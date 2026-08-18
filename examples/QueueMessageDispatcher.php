<?php

declare(strict_types=1);

namespace App\AiChat;

use ADT\AiChat\Dispatch\MessageDispatcher;

/**
 * Hand-off to background processing - here over adt/background-queue, but any
 * queue (or even a synchronous call in a CLI tool) works. The consuming worker
 * maps the callback back to {@see ChatService::processMessage()}.
 *
 * With adt/background-queue the other half of the wiring is:
 *
 *   backgroundQueue:
 *       callbacks:
 *           aiChatProcessMessage:
 *               callback: [@App\AiChat\ChatService, processMessage]
 *               # a dedicated queue: a turn takes minutes and must not block
 *               # e-mails or other jobs sharing the default queue
 *               queue: %backgroundQueue.queue%-ai-chat
 *
 * ...plus a dedicated consumer process (supervisor):
 *
 *   [program:ai_chat]
 *   command=php bin/console background-queue:consume myapp-ai-chat
 */
class QueueMessageDispatcher implements MessageDispatcher
{
	public function __construct(
		private readonly \ADT\BackgroundQueue\BackgroundQueue $queue,
	) {
	}

	public function dispatch(int|string $conversationId, string $userMessage): void
	{
		$this->queue->publish('aiChatProcessMessage', [
			'conversationId' => $conversationId,
			'userMessage' => $userMessage,
		]);
	}
}
