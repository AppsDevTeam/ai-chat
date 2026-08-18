<?php

declare(strict_types=1);

namespace App\AiChat\Entity;

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Entity\ConversationTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * The trait maps the shared columns (title, mca_session_id, deleted, created,
 * updated) including their anonymization defaults; your side adds the id and the
 * owner relation - the package never touches ownership.
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

	/** Replace with a relation to your own user entity. */
	#[ORM\Column(name: 'user_id')]
	public int $userId;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
		$this->created = new \DateTimeImmutable();
		$this->updated = new \DateTimeImmutable();
	}

	public function getId(): ?int
	{
		return $this->id;
	}
}
