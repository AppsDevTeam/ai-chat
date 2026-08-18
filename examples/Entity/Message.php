<?php

declare(strict_types=1);

namespace App\AiChat\Entity;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\ORM\Mapping as ORM;

/**
 * The package has no message contract on purpose - persistence of the transcript
 * is fully yours. This is the minimal shape the reference ChatService works with:
 * the user text, the (redacted) reply, the tool calls of the turn and the token
 * usage for a context-window gauge and billing.
 *
 * Both free-text columns are marked for the anonymized views: the transcript may
 * contain anything the user typed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_chat_message')]
class Message
{
	public const AUTHOR_USER = 'user';
	public const AUTHOR_AI = 'ai';

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: Conversation::class)]
	#[ORM\JoinColumn(nullable: false)]
	public Conversation $conversation;

	#[ORM\Column(length: 16)]
	public string $author = self::AUTHOR_USER;

	#[ORM\Column(type: 'text')]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	public string $content = '';

	/** JSON-encoded TurnResult::$toolData - charts/tables for the frontend. */
	#[ORM\Column(name: 'tool_data', type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	public ?string $toolData = null;

	#[ORM\Column(name: 'tokens_input', nullable: true)]
	public ?int $tokensInput = null;

	#[ORM\Column(name: 'tokens_output', nullable: true)]
	public ?int $tokensOutput = null;

	#[ORM\Column(options: ['default' => false])]
	public bool $error = false;

	#[ORM\Column]
	public \DateTimeImmutable $created;

	public function __construct(Conversation $conversation, string $content)
	{
		$this->conversation = $conversation;
		$this->content = $content;
		$this->created = new \DateTimeImmutable();
	}
}
