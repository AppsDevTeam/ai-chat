<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Entity\AgentInterface;
use ADT\AiChat\Exception\AiChatException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotently provisions the Managed Agents agent and environment for an
 * anonymized schema (= one instance of your application) and stores the mapping.
 *
 * - When a mapping for the schema already exists, the SAME agent is updated with
 *   the current system prompt and tools (new version, same id) - the database
 *   structure changes over time and the agent must follow.
 * - Otherwise a new environment and agent are created and their ids stored, so
 *   nothing is ever copied into config by hand.
 *
 * Run it right after regenerating the anonymized views, typically from the same
 * deploy command.
 */
class AgentProvisioner
{
	/**
	 * @param class-string<AgentInterface> $agentClass your entity implementing
	 *        {@see AgentInterface} (use {@see \ADT\AiChat\Entity\AgentTrait})
	 * @param string $namePrefix prefix of the agent/environment names on the
	 *        Anthropic side, so instances are recognisable in their console
	 */
	public function __construct(
		private readonly ManagedAgentsClient $client,
		private readonly SystemPromptBuilder $promptBuilder,
		private readonly ToolExecutor $toolExecutor,
		private readonly EntityManagerInterface $em,
		private readonly string $agentClass,
		private readonly string $namePrefix = 'ai-chat',
	) {
	}

	/**
	 * @return array{0: AgentInterface, 1: bool} [mapping, created-now? (false = only updated)]
	 * @throws AiChatException
	 */
	public function ensureProvisioned(string $schemaName): array
	{
		$systemPrompt = $this->promptBuilder->build();
		$tools = $this->toolExecutor->getCustomToolDefinitions();

		$existing = $this->findForSchema($schemaName);
		if ($existing !== null) {
			$this->client->updateAgent($existing->getAgentId(), $systemPrompt, $tools);
			// The timestamp is written only after a successful API call, so it proves
			// the re-provisioning really happened (not just that a deploy ran).
			$existing->setUpdated(new \DateTimeImmutable());
			$this->em->flush();

			return [$existing, false];
		}

		// Environment and agent carry the schema in their name, so every instance
		// has its own and they are tell-apart-able in the Anthropic console.
		$environment = $this->client->createEnvironment($this->namePrefix . '-' . $schemaName);
		$environmentId = (string) ($environment['id'] ?? '');

		$agent = $this->client->createAgent(
			$this->namePrefix . ' (' . $schemaName . ')',
			$systemPrompt,
			$tools,
		);
		$agentId = (string) ($agent['id'] ?? '');

		if ($environmentId === '' || $agentId === '') {
			throw new AiChatException('Managed Agents: failed to create the agent/environment (no id in the response).');
		}

		/** @var AgentInterface $mapping */
		$mapping = new ($this->agentClass)();
		$mapping->setSchemaName($schemaName);
		$mapping->setAgentId($agentId);
		$mapping->setEnvironmentId($environmentId);
		$mapping->setCreated(new \DateTimeImmutable());

		$this->em->persist($mapping);
		$this->em->flush();

		return [$mapping, true];
	}

	public function findForSchema(string $schemaName): ?AgentInterface
	{
		return $this->em->getRepository($this->agentClass)->findOneBy(['schemaName' => $schemaName]);
	}

	/**
	 * Resolver for {@see AgentTurnRunner}: looks the agent up by the schema at the
	 * time a session is created, with a helpful error when provisioning has not
	 * run yet.
	 *
	 * @return callable(): AgentResolution
	 */
	public function resolver(string $schemaName): callable
	{
		return function () use ($schemaName): AgentResolution {
			$agent = $this->findForSchema($schemaName);

			if ($agent === null) {
				throw new AiChatException(
					'No Managed Agents agent is provisioned for schema "' . $schemaName . '". '
					. 'Run the provisioning (AgentProvisioner::ensureProvisioned) first, typically as part of the deploy.',
				);
			}

			return new AgentResolution($agent->getAgentId(), $agent->getEnvironmentId());
		};
	}
}
