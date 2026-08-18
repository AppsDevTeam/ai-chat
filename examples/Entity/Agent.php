<?php

declare(strict_types=1);

namespace App\AiChat\Entity;

use ADT\AiChat\Entity\AgentInterface;
use ADT\AiChat\Entity\AgentTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping of an anonymized schema to the provisioned agent + environment.
 * Created and looked up by {@see \ADT\AiChat\AgentProvisioner}; nothing is ever
 * copied into config by hand.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_chat_agent')]
class Agent implements AgentInterface
{
	use AgentTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;
}
