<?php

declare(strict_types=1);

namespace App\AiChat\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\ConversationTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * The trait maps the shared columns (title, mca_session_id, deleted, created,
 * updated) including their anonymization defaults; your side adds the id and the
 * owner - here a relation to your user entity, named by ChatOptions::$ownerField.
 *
 * ChatService creates conversations with `new Conversation()`, so the constructor
 * must not require arguments.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_chat_conversation')]
class Conversation implements ConversationInterface
{
	use ConversationTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: \App\Model\Entity\User::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	public \App\Model\Entity\User $user;

	public function getId(): ?int
	{
		return $this->id;
	}
}
