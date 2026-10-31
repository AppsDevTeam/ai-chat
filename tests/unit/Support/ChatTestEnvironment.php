<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\AgentProvisioner;
use ADT\AiChat\AiChatOptions;
use ADT\AiChat\ChatOptions;
use ADT\AiChat\ChatService;
use ADT\AiChat\Http\ChatEndpoint;
use ADT\AiChat\PiiResponseFilter;
use ADT\AiChat\PiiValueCollector;
use ADT\AiChat\SystemPromptBuilder;
use ADT\AiChat\Tests\Support\Entity\TestAgent;
use ADT\AiChat\Tests\Support\Entity\TestConversation;
use ADT\AiChat\Tests\Support\Entity\TestMessage;
use ADT\AiChat\Tests\Support\Entity\TestOwner;
use ADT\AiChat\ToolHandler;
use ADT\DoctrineAnonymization\AnonymizationPolicy;
use ADT\DoctrineAnonymization\PersonalDataColumns;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * A ChatService over an in-memory SQLite database with the test entities, the
 * scriptable API fake and a provisioned agent.
 */
final class ChatTestEnvironment
{
	public readonly EntityManager $em;
	public readonly FakeManagedAgentsClient $client;
	public readonly StubQueryExecutor $queryExecutor;
	public readonly RecordingDispatcher $dispatcher;
	/** Not provisioned up front - the first session provisions the agent lazily. */
	public readonly AgentProvisioner $provisioner;
	public readonly ChatService $chat;
	public readonly ChatEndpoint $endpoint;

	private int $scriptedTurns = 0;

	public function __construct(?ChatOptions $options = null)
	{
		$paths = [__DIR__ . '/Entity'];
		$config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
			? ORMSetup::createAttributeMetadataConfig($paths, true)
			: ORMSetup::createAttributeMetadataConfiguration($paths, true);

		if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
			$config->enableNativeLazyObjects(true);
		}

		$this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
		(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

		$this->client = new FakeManagedAgentsClient();
		$this->queryExecutor = StubQueryExecutor::withTables(['client']);
		$this->dispatcher = new RecordingDispatcher();

		$toolHandler = new ToolHandler($this->queryExecutor);
		$provisioner = new AgentProvisioner(
			$this->client,
			new SystemPromptBuilder($this->queryExecutor, SystemPromptBuilder::defaultTemplatePath()),
			$toolHandler,
			$this->em,
			TestAgent::class,
		);
		$this->provisioner = $provisioner;

		$this->chat = new ChatService(
			$this->em,
			$this->client,
			$toolHandler,
			$this->queryExecutor,
			$provisioner,
			$this->dispatcher,
			new PiiResponseFilter(),
			new PiiValueCollector(new PersonalDataColumns($this->em, new AnonymizationPolicy())),
			$options ?? new ChatOptions(TestConversation::class, TestMessage::class, ownerField: 'owner'),
			new AiChatOptions(0, 0.2, 5),
		);

		$this->endpoint = new ChatEndpoint($this->chat, $this->queryExecutor);
	}

	public function owner(): TestOwner
	{
		$owner = new TestOwner();
		$this->em->persist($owner);
		$this->em->flush();

		return $owner;
	}

	/** Releases a plain reply for the next turn. */
	public function scriptReply(string $text): void
	{
		$turn = ++$this->scriptedTurns;
		$this->client->script([
			[['id' => 'evt_msg_' . $turn, 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => $text]]]],
			[['id' => 'evt_idle_' . $turn, 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']]],
		]);
	}
}
