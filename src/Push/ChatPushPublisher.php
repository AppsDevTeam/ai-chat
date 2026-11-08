<?php

declare(strict_types=1);

namespace ADT\AiChat\Push;

use ADT\AiChat\Entity\ConversationInterface;

/**
 * Push to the widget: the worker announces that a conversation changed (progress of
 * the turn, the reply) and the widget polls right away instead of on its next
 * scheduled poll. Only the signal goes through the push - no message content, the
 * widget still reads the conversation through the endpoint with its access checks.
 *
 * Optional: without it the widget keeps polling. Publishing is best effort - a hub
 * that is down must never break a turn.
 */
interface ChatPushPublisher
{
	/** Called by the worker after it stored a change of the conversation. */
	public function conversationChanged(ConversationInterface $conversation): void;

	/**
	 * What the widget needs to subscribe to the conversation - sent in the endpoint
	 * responses ({@see \ADT\AiChat\Http\ChatEndpoint}); null = no push for it.
	 *
	 * @return array{url: string, topic: string, token: string}|null
	 */
	public function subscription(ConversationInterface $conversation): ?array;
}
