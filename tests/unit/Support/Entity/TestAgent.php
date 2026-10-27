<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use ADT\AiChat\Entity\AgentInterface;
use ADT\AiChat\Entity\AgentTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent')]
class TestAgent implements AgentInterface
{
	use AgentTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;
}
