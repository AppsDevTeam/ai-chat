<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use ADT\AiChat\Mcp\McpToken;
use ADT\AiChat\Mcp\McpTokenTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_mcp_token')]
class TestMcpToken implements McpToken
{
	use McpTokenTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\ManyToOne(targetEntity: TestOwner::class)]
	public ?TestOwner $owner = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getTokenOwner(): ?TestOwner
	{
		return $this->owner;
	}
}
