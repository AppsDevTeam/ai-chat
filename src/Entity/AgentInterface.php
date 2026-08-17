<?php

declare(strict_types=1);

namespace ADT\AiChat\Entity;

/**
 * Mapping of an anonymized schema (= one instance of your application) to the
 * Managed Agents agent and environment provisioned for it.
 *
 * Created automatically by {@see \ADT\AiChat\AgentProvisioner}; at runtime the
 * engine looks the agent up by the schema name, so no ids are ever copied into
 * config by hand.
 */
interface AgentInterface
{
	public function getSchemaName(): string;

	public function setSchemaName(string $schemaName): static;

	public function getAgentId(): string;

	public function setAgentId(string $agentId): static;

	public function getEnvironmentId(): string;

	public function setEnvironmentId(string $environmentId): static;

	public function setCreated(\DateTimeImmutable $created): static;

	/** When the agent was last re-provisioned (prompt/tools updated); null = never. */
	public function setUpdated(?\DateTimeImmutable $updated): static;
}
