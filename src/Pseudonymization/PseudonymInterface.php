<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use ADT\AiChat\Entity\ConversationInterface;

/**
 * The value behind an anchor ([PERSON_1], [EMAIL_1] ...) of one conversation - stays
 * on your side, the AI only ever sees the anchor.
 *
 * Implement it on your entity with {@see PseudonymTrait}; the entity adds the id,
 * the relation to its conversation entity (field `conversation` by default) and a
 * unique index over (conversation, anchor). Exclude the entity from the anonymized
 * schema - the values must never reach the AI. {@see Pseudonymizer} creates
 * pseudonyms with `new $class()`.
 */
interface PseudonymInterface
{
	public function getConversation(): ConversationInterface;

	public function setConversation(ConversationInterface $conversation): static;

	public function getAnchor(): string;

	public function setAnchor(string $anchor): static;

	public function getValue(): string;

	public function setValue(string $value): static;
}
