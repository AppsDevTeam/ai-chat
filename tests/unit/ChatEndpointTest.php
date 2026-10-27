<?php

declare(strict_types=1);

use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestMessage;

class ChatEndpointTest extends \Codeception\Test\Unit
{
	private ChatTestEnvironment $env;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
	}

	public function testSendWithoutAConversationStartsOne(): void
	{
		$owner = $this->env->owner();

		$response = $this->env->endpoint->send($owner, null, 'First question');

		$this->assertTrue($response['success']);
		$this->assertTrue($response['queued']);
		$this->assertSame('First question', $response['conversation']['title']);
		$this->assertSame('First question', $response['message']['content']);
		$this->assertSame($response['message']['id'], $response['lastMessageId']);
		$this->assertCount(1, $this->env->dispatcher->dispatched);
	}

	public function testSendAndPoll(): void
	{
		$owner = $this->env->owner();
		$sent = $this->env->endpoint->send($owner, null, 'Hi');
		$conversationId = $sent['conversation']['id'];

		$this->assertSame([], $this->env->endpoint->poll($owner, $conversationId, $sent['lastMessageId'])['messages']);

		$this->env->scriptReply('Hello');
		$this->env->chat->processQueuedMessage($conversationId, 'Hi');

		$poll = $this->env->endpoint->poll($owner, $conversationId, $sent['lastMessageId']);
		$this->assertTrue($poll['success']);
		$this->assertCount(1, $poll['messages']);
		$this->assertSame('ai', $poll['messages'][0]['author']);
		$this->assertSame('Hello', $poll['messages'][0]['content']);

		$load = $this->env->endpoint->load($owner, $conversationId);
		$this->assertCount(2, $load['messages']);
		$this->assertFalse($load['readOnly']);
	}

	public function testForeignConversationIsNotFound(): void
	{
		$alice = $this->env->owner();
		$bob = $this->env->owner();
		$conversationId = $this->env->endpoint->create($alice)['conversation']['id'];
		$this->env->chat->setTranslator(static fn(string $key): string => $key);

		foreach ([
			$this->env->endpoint->load($bob, $conversationId),
			$this->env->endpoint->send($bob, $conversationId, 'Hi'),
			$this->env->endpoint->poll($bob, $conversationId, 0),
			$this->env->endpoint->delete($bob, $conversationId),
		] as $response) {
			$this->assertSame(['success' => false, 'error' => 'aiChat.error.conversationNotFound'], $response);
		}

		$this->assertSame([], $this->env->dispatcher->dispatched);
		$this->assertCount(1, $this->env->endpoint->conversations($alice)['conversations']);
	}

	public function testErrorsBecomeAFailurePayload(): void
	{
		$owner = $this->env->owner();

		$response = $this->env->endpoint->send($owner, null, '');

		$this->assertSame(['success' => false, 'error' => 'The message cannot be empty.'], $response);
	}

	public function testToolDataWhitelist(): void
	{
		$toolData = [
			['tool_name' => 'get_database_schema', 'tool_input' => [], 'tool_result' => ['tables' => ['client']]],
			['tool_name' => 'execute_sql', 'tool_input' => ['sql' => 'SELECT 1'], 'tool_result' => ['result' => [['a' => 1]]]],
			['tool_name' => 'render_chart', 'tool_input' => ['x' => 1], 'tool_result' => ['type' => 'chart', 'chart_type' => 'bar']],
			['tool_name' => 'render_table', 'tool_input' => [], 'tool_result' => ['type' => 'table', 'columns' => ['a']]],
			[
				'tool_name' => 'export_csv',
				'tool_input' => ['sql' => 'SELECT * FROM client'],
				'tool_result' => ['type' => 'export', 'token' => 'tok', 'filename' => 'c.csv', 'title' => 'C', 'sql' => 'leak'],
			],
		];

		$this->assertSame([
			['tool_result' => ['type' => 'chart', 'chart_type' => 'bar']],
			['tool_result' => ['type' => 'table', 'columns' => ['a']]],
			['messageId' => 5, 'tool_result' => ['type' => 'export', 'title' => 'C', 'filename' => 'c.csv', 'token' => 'tok']],
		], $this->env->endpoint->clientToolData($toolData, 5));
	}

	public function testExport(): void
	{
		$owner = $this->env->owner();
		$conversation = $this->env->chat->createConversation($owner);
		$message = (new TestMessage())
			->setConversation($conversation)
			->setAuthor('ai')
			->setContent('Here you go')
			->setCreated(new \DateTimeImmutable())
			->setToolData([[
				'tool_name' => 'export_csv',
				'tool_input' => ['sql' => 'SELECT * FROM client'],
				'tool_result' => ['type' => 'export', 'token' => 'tok', 'filename' => '../../etc/clients.csv', 'title' => 'C'],
			]]);
		$this->env->em->persist($message);
		$this->env->em->flush();
		$this->env->queryExecutor->rows = [['id' => 1, 'name' => 'Šárka'], ['id' => 2, 'name' => 'Jan']];

		$this->assertNull($this->env->endpoint->export($owner, $conversation->getId(), $message->getId(), 'wrong'));
		$this->assertNull($this->env->endpoint->export($this->env->owner(), $conversation->getId(), $message->getId(), 'tok'));

		$export = $this->env->endpoint->export($owner, $conversation->getId(), $message->getId(), 'tok');
		$this->assertNotNull($export);
		$this->assertStringContainsString('filename="clients.csv"', $export->getHeaders()['Content-Disposition']);

		$out = fopen('php://memory', 'w+');
		$export->stream($out);
		rewind($out);
		$this->assertSame("\u{FEFF}id,name\n1,Šárka\n2,Jan\n", stream_get_contents($out));
	}
}
