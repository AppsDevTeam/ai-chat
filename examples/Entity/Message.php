<?php

declare(strict_types=1);

namespace App\AiChat\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Entity\MessageTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * The transcript. The trait maps author, content, tool data, attachments, token
 * usage, the error flag and the timestamp (free-text columns are marked for the
 * anonymized views); your side adds the id and the relation to the conversation,
 * named by ChatOptions::$conversationField.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_chat_message')]
class Message implements MessageInterface
{
	use MessageTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: Conversation::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	protected ConversationInterface $conversation;

	public function getId(): ?int
	{
		return $this->id;
	}
}
