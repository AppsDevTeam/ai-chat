<?php

declare(strict_types=1);

use ADT\AiChat\AgentProvisioner;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\SystemPromptBuilder;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestAgent;
use ADT\AiChat\Tests\Support\StubQueryExecutor;
use ADT\AiChat\ToolHandler;

class AgentProvisionerTest extends \Codeception\Test\Unit
{
	private ChatTestEnvironment $env;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
	}

	public function testResolverCreatesTheAgentWhenMissing(): void
	{
		$schema = $this->env->queryExecutor->getSchemaName();
		$this->assertNull($this->env->provisioner->findForSchema($schema));

		$resolution = ($this->env->provisioner->resolver($schema))();

		$this->assertSame('agent_fake', $resolution->agentId);
		$this->assertSame('env_fake', $resolution->environmentId);
		$this->assertSame(1, $this->env->client->createdAgents);
		$this->assertNotNull($this->env->provisioner->findForSchema($schema)?->getFingerprint());
	}

	public function testResolverSkipsTheApiWhenNothingChanged(): void
	{
		$resolver = $this->env->provisioner->resolver($this->env->queryExecutor->getSchemaName());

		$resolver();
		$resolver();

		$this->assertSame(1, $this->env->client->createdAgents);
		$this->assertSame([], $this->env->client->updatedAgents);
	}

	public function testResolverUpdatesTheAgentWhenThePromptChanged(): void
	{
		$schema = $this->env->queryExecutor->getSchemaName();
		($this->env->provisioner->resolver($schema))();

		// A new release with another table in the anonymized schema = another prompt
		($this->provisionerOver(StubQueryExecutor::withTables(['client', 'invoice']))->resolver($schema))();

		$this->assertSame(1, $this->env->client->createdAgents);
		$this->assertSame(['agent_fake'], $this->env->client->updatedAgents);
	}

	public function testAgentWithoutFingerprintIsUpdatedOnce(): void
	{
		// Mapping provisioned before fingerprints existed
		$schema = $this->env->queryExecutor->getSchemaName();
		$this->env->provisioner->ensureProvisioned($schema);
		$this->env->provisioner->findForSchema($schema)->setFingerprint(null);
		$this->env->em->flush();

		$resolver = $this->env->provisioner->resolver($schema);
		$resolver();
		$resolver();

		$this->assertSame(['agent_fake'], $this->env->client->updatedAgents);
	}

	public function testEnsureProvisionedUpdatesByDefaultEvenWhenCurrent(): void
	{
		$schema = $this->env->queryExecutor->getSchemaName();
		$this->env->provisioner->ensureProvisioned($schema);

		[, $created] = $this->env->provisioner->ensureProvisioned($schema);

		$this->assertFalse($created);
		$this->assertSame(['agent_fake'], $this->env->client->updatedAgents);
	}

	public function testResolverFailureIsAnAiChatExceptionAndStoresNothing(): void
	{
		$this->env->client->failAgentCalls = true;
		$schema = $this->env->queryExecutor->getSchemaName();

		try {
			($this->env->provisioner->resolver($schema))();
			$this->fail('Expected AiChatException');
		} catch (AiChatException $e) {
			$this->assertStringContainsString('401', $e->getMessage());
		}

		$this->assertNull($this->env->provisioner->findForSchema($schema));
	}

	private function provisionerOver(StubQueryExecutor $queryExecutor): AgentProvisioner
	{
		return new AgentProvisioner(
			$this->env->client,
			new SystemPromptBuilder($queryExecutor, SystemPromptBuilder::defaultTemplatePath()),
			new ToolHandler($queryExecutor),
			$this->env->em,
			TestAgent::class,
		);
	}
}
