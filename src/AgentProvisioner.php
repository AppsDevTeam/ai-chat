<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Entity\AgentInterface;
use ADT\AiChat\Exception\AiChatException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisions the Managed Agents agent and environment for an anonymized schema
 * (= one instance of your application) and stores the mapping.
 *
 * Lazily by default: {@see resolver()} runs before every new session and
 * - creates the agent and environment when the schema has none yet,
 * - updates the SAME agent (new version, same id) when the system prompt or the
 *   tools changed since the last provisioning - detected by a fingerprint stored on
 *   the mapping, so an unchanged agent costs no API call.
 *
 * A deploy therefore needs no API key and no call to Anthropic; the first message
 * after it brings the agent up to date. {@see ensureProvisioned()} does the same on
 * demand (e.g. from a console command, or to validate a newly entered API key).
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
	 * Creates the agent, or updates it with the current system prompt and tools.
	 *
	 * @param bool $force update even when the fingerprint says nothing changed
	 * @return array{0: AgentInterface, 1: bool} [mapping, created-now? (false = updated or already current)]
	 * @throws AiChatException
	 */
	public function ensureProvisioned(string $schemaName, bool $force = true): array
	{
		$systemPrompt = $this->promptBuilder->build();
		$tools = $this->toolExecutor->getCustomToolDefinitions();
		$fingerprint = self::fingerprint($systemPrompt, $tools);

		$existing = $this->findForSchema($schemaName);
		if ($existing !== null) {
			if (!$force && $existing->getFingerprint() === $fingerprint) {
				return [$existing, false];
			}

			$this->client->updateAgent($existing->getAgentId(), $systemPrompt, $tools);
			// The timestamp is written only after a successful API call, so it proves
			// the re-provisioning really happened.
			$existing->setUpdated(new \DateTimeImmutable());
			$existing->setFingerprint($fingerprint);
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
		$mapping->setFingerprint($fingerprint);

		$this->em->persist($mapping);
		$this->em->flush();

		return [$mapping, true];
	}

	/**
	 * Identifies what the agent was provisioned with. Tools are hashed in the order
	 * they are defined - a reorder costs one needless update, nothing more.
	 *
	 * @param list<array<string, mixed>> $tools
	 */
	public static function fingerprint(string $systemPrompt, array $tools): string
	{
		return hash('sha256', $systemPrompt . "\0" . json_encode($tools, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}

	public function findForSchema(string $schemaName): ?AgentInterface
	{
		return $this->em->getRepository($this->agentClass)->findOneBy(['schemaName' => $schemaName]);
	}

	/**
	 * Resolver for {@see AgentTurnRunner}, called whenever a new session is created:
	 * provisions the agent when it is missing and updates it when the system prompt
	 * or the tools changed, so a deploy does not have to.
	 *
	 * @return callable(): AgentResolution
	 */
	public function resolver(string $schemaName): callable
	{
		return function () use ($schemaName): AgentResolution {
			try {
				[$agent] = $this->ensureProvisioned($schemaName, force: false);
			} catch (AiChatException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new AiChatException(
					'The Managed Agents agent for schema "' . $schemaName . '" could not be provisioned: ' . $e->getMessage(),
					previous: $e,
				);
			}

			return new AgentResolution($agent->getAgentId(), $agent->getEnvironmentId());
		};
	}
}
