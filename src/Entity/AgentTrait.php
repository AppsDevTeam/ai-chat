<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

use ADT\DoctrineAnonymization\Attributes\Description;
use Doctrine\ORM\Mapping as ORM;

/**
 * Default mapping for {@see AgentInterface}. Add your own id on the entity.
 */
trait AgentTrait
{
	#[ORM\Column(name: 'schema_name', length: 191, unique: true)]
	#[Description('Anonymized schema name this Managed Agent belongs to (the per-instance key).')]
	protected string $schemaName;

	#[ORM\Column(name: 'agent_id', length: 64)]
	#[Description('Anthropic Managed Agents agent ID provisioned for this schema.')]
	protected string $agentId;

	#[ORM\Column(name: 'environment_id', length: 64)]
	#[Description('Anthropic Managed Agents environment ID provisioned for this schema.')]
	protected string $environmentId;

	#[ORM\Column]
	#[Description('Date and time when the agent/environment was provisioned.')]
	protected \DateTimeImmutable $created;

	#[ORM\Column(nullable: true)]
	#[Description('Date and time when the agent was last re-provisioned (system prompt / tools updated); null if never updated since creation.')]
	protected ?\DateTimeImmutable $updated = null;

	#[ORM\Column(length: 64, nullable: true)]
	#[Description('SHA-256 of the system prompt and tool definitions the agent was last provisioned with; a different value means the agent is outdated and gets updated before the next session.')]
	protected ?string $fingerprint = null;

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

	public function getFingerprint(): ?string
	{
		return $this->fingerprint;
	}

	public function setFingerprint(?string $fingerprint): static
	{
		$this->fingerprint = $fingerprint;
		return $this;
	}
}
