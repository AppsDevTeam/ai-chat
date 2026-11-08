<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Decides whether a message continues the open conversation or starts a new one -
 * for messages whose sender did not pick the conversation himself, e.g. a dictated
 * command that lands in whatever conversation was open last
 * ({@see Http\ChatEndpoint::send()} with $routeConversation).
 *
 * A new conversation is cheaper and safer than a long one: every turn sends the whole
 * conversation again (the prompt cache lasts only minutes), the context fills up and
 * a pronoun may point to someone from much earlier. Continue only when the message
 * clearly follows up the last one.
 *
 * Runs in the web request before the message is queued - keep it fast (rules, a local
 * classifier). The text is the user's, not pseudonymized.
 */
interface ConversationRouter
{
	/**
	 * @param \DateTimeImmutable $lastMessageAt the last message of the conversation (a user message or a reply)
	 * @param string|null $lastReply the text of the AI reply when it is the last message (not an error), e.g. to
	 *        see that the AI asked something and the message is the answer ("Which patient?" - "Pavel Novak")
	 * @return bool true = the message goes to the open conversation, false = a new conversation
	 */
	public function continues(string $userMessage, \DateTimeImmutable $lastMessageAt, ?string $lastReply): bool;
}
