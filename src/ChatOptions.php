<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Configuration of {@see ChatService}: which entities it works with and the limits
 * of the web-request side. The agent turn itself is tuned by {@see AiChatOptions}.
 */
class ChatOptions
{
	/**
	 * @param class-string<Entity\ConversationInterface> $conversationClass
	 * @param class-string<Entity\MessageInterface> $messageClass
	 * @param string $ownerField association (or column) of the conversation that holds
	 *        its owner - the user, identity or account the conversations belong to
	 * @param string $conversationField association of the message to its conversation
	 * @param int $rateLimitPerMinute user messages per owner and minute
	 * @param int $rateLimitPerHour user messages per owner and hour
	 * @param int $contextWindowTokens window size of the model, for the context gauge
	 * @param int $titleMaxLength the conversation title is cut from the first message
	 * @param ModelSelection $modelSelection models and effort levels the user may pick;
	 *        the context gauge takes the window of the chosen model from it
	 * @param int $ratingCommentMaxLength longer comments of a conversation rating are cut
	 */
	public function __construct(
		public readonly string $conversationClass,
		public readonly string $messageClass,
		public readonly string $ownerField = 'user',
		public readonly string $conversationField = 'conversation',
		public readonly int $rateLimitPerMinute = 20,
		public readonly int $rateLimitPerHour = 200,
		public readonly int $contextWindowTokens = 200000,
		public readonly int $titleMaxLength = 100,
		public readonly AttachmentPolicy $attachments = new AttachmentPolicy(),
		public readonly ModelSelection $modelSelection = new ModelSelection(),
		public readonly int $ratingCommentMaxLength = 2000,
	) {
	}

	/**
	 * For DI configs: the same keys as the constructor arguments, `attachments` as an
	 * array of {@see AttachmentPolicy::fromArray()} and `modelSelection` as an array of
	 * {@see ModelSelection::fromArray()}.
	 *
	 * @param array<string, mixed> $options
	 */
	public static function fromArray(array $options): self
	{
		if (isset($options['attachments']) && is_array($options['attachments'])) {
			$options['attachments'] = AttachmentPolicy::fromArray($options['attachments']);
		}

		if (isset($options['modelSelection']) && is_array($options['modelSelection'])) {
			$options['modelSelection'] = ModelSelection::fromArray($options['modelSelection']);
		}

		return new self(...$options);
	}
}
