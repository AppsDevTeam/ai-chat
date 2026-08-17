<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see AgentInterface}. Add your own id on the entity.
 */
trait AgentTrait
{
	#[ORM\Column(name: 'schema_name', length: 191, unique: true)]
	protected string $schemaName;

	#[ORM\Column(name: 'agent_id', length: 64)]
	protected string $agentId;

	#[ORM\Column(name: 'environment_id', length: 64)]
	protected string $environmentId;

	#[ORM\Column]
	protected \DateTimeImmutable $created;

	#[ORM\Column(nullable: true)]
	protected ?\DateTimeImmutable $updated = null;

	public function getSchemaName(): string
	{
		return $this->schemaName;
	}

	public function setSchemaName(string $schemaName): static
	{
		$this->schemaName = $schemaName;
		return $this;
	}

	public function getAgentId(): string
	{
		return $this->agentId;
	}

	public function setAgentId(string $agentId): static
	{
		$this->agentId = $agentId;
		return $this;
	}

	public function getEnvironmentId(): string
	{
		return $this->environmentId;
	}

	public function setEnvironmentId(string $environmentId): static
	{
		$this->environmentId = $environmentId;
		return $this;
	}

	public function setCreated(\DateTimeImmutable $created): static
	{
		$this->created = $created;
		return $this;
	}

	public function setUpdated(?\DateTimeImmutable $updated): static
	{
		$this->updated = $updated;
		return $this;
	}
}
