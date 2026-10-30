<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use ADT\AiChat\Entity\ConversationInterface;

/**
 * Notified about every check of a user message - for an audit log. Never store the
 * values: {@see PseudonymizedText::$findings} holds counts only.
 */
interface PiiCheckListener
{
	/** The message went to the AI. */
	public function checked(ConversationInterface $conversation, PseudonymizedText $result): void;

	/** The required check did not run, the message did not go to the AI. */
	public function unavailable(ConversationInterface $conversation, PiiCheckUnavailableException $exception): void;
}
