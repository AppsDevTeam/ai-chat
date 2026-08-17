<?php

declare(strict_types=1);

use ADT\AiChat\AgentResolution;
use ADT\AiChat\AgentTurnRunner;
use ADT\AiChat\AiChatOptions;
use ADT\AiChat\Exception\TurnTimeoutException;
use ADT\AiChat\Tests\Support\FakeConversation;
use ADT\AiChat\Tests\Support\FakeManagedAgentsClient;
use ADT\AiChat\ToolExecutor;

class AgentTurnRunnerTest extends \Codeception\Test\Unit
{
	private FakeManagedAgentsClient $client;

	/** @var list<array{0: string, 1: array}> */
	private array $toolCalls = [];

	protected function _before(): void
	{
		$this->client = new FakeManagedAgentsClient();
		$this->toolCalls = [];
	}

	private function runner(int $maxPolls = 20, int $maxToolCalls = 5): AgentTurnRunner
	{
		$toolExecutor = new class ($this->toolCalls) implements ToolExecutor {
			public function __construct(private array &$calls)
			{
			}

			public function handleToolCall(string $toolName, array $input): array
			{
				$this->calls[] = [$toolName, $input];

				return $toolName === 'failing_tool'
					? ['error' => 'boom']
					: ['result' => [['answer' => 42]], 'row_count' => 1];
			}

			public function getCustomToolDefinitions(): array
			{
				return [];
			}
		};

		return new AgentTurnRunner(
			$this->client,
			$toolExecutor,
			fn(): AgentResolution => new AgentResolution('agent_1', 'env_1'),
			// pollIntervalMicroseconds = 0, so tests do not sleep
			new AiChatOptions(0, $maxPolls, $maxToolCalls),
		);
	}

	private function conversation(?string $sessionId = null): FakeConversation
	{
		$conversation = new FakeConversation();
		$conversation->setSessionId($sessionId);

		return $conversation;
	}

	public function testSimpleTurnCollectsTextAndTokens(): void
	{
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Hello']]],
			['id' => 'e2', 'type' => 'span.model_request_end', 'model_usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 90, 'output_tokens' => 7]],
			['id' => 'e3', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$persisted = [];
		$result = $this->runner()->run($this->conversation(), 'Hi', function (?string $id) use (&$persisted): void {
			$persisted[] = $id;
		});

		$this->assertSame('Hello', $result->text);
		// Window usage = input + cache counters of the last request.
		$this->assertSame(100, $result->tokensInput);
		$this->assertSame(7, $result->tokensOutput);
		$this->assertSame([], $result->toolData);

		// A session was created for the fresh conversation and persisted right away.
		$this->assertSame(['sesn_1'], $persisted);
		// The user message was delivered.
		$this->assertSame('user.message', $this->client->sentEvents[0][0]['type']);
	}

	public function testToolRoundTrip(): void
	{
		$this->client->frames = [
			[
				['id' => 't1', 'type' => 'agent.custom_tool_use', 'name' => 'execute_sql', 'input' => ['sql' => 'SELECT 1']],
				['id' => 's1', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'requires_action']],
			],
			[
				['id' => 'm1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Done']]],
				['id' => 's2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
			],
		];

		$result = $this->runner()->run($this->conversation('sesn_existing'), 'Count clients', fn() => null);

		// The tool was executed locally and its result sent back to the session.
		$this->assertSame([['execute_sql', ['sql' => 'SELECT 1']]], $this->toolCalls);
		$toolResultEvents = array_values(array_filter(
			array_merge(...$this->client->sentEvents),
			static fn(array $e): bool => $e['type'] === 'user.custom_tool_result',
		));
		$this->assertCount(1, $toolResultEvents);
		$this->assertSame('t1', $toolResultEvents[0]['custom_tool_use_id']);
		$this->assertFalse($toolResultEvents[0]['is_error']);

		$this->assertSame('Done', $result->text);
		$this->assertCount(1, $result->toolData);
		$this->assertSame('execute_sql', $result->toolData[0]['tool_name']);
	}

	public function testFailedToolIsReportedAsErrorToTheAgent(): void
	{
		$this->client->frames = [
			[
				['id' => 't1', 'type' => 'agent.custom_tool_use', 'name' => 'failing_tool', 'input' => []],
			],
			[
				['id' => 's1', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
			],
		];

		$this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null);

		$toolResultEvents = array_values(array_filter(
			array_merge(...$this->client->sentEvents),
			static fn(array $e): bool => $e['type'] === 'user.custom_tool_result',
		));
		$this->assertTrue($toolResultEvents[0]['is_error']);
	}

	public function testHistoryEventsAreIgnored(): void
	{
		$this->client->history = [
			['id' => 'old1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'OLD REPLY']]],
		];
		$this->client->frames = [[
			['id' => 'new1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'New reply']]],
			['id' => 'new2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$result = $this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null);

		$this->assertSame('New reply', $result->text);
	}

	public function testRunningOutOfPollsThrowsTimeout(): void
	{
		// No terminal event ever arrives.
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'partial']]],
		]];

		$this->expectException(TurnTimeoutException::class);
		$this->runner(maxPolls: 3)->run($this->conversation('sesn_existing'), 'x', fn() => null);
	}

	public function testTooManyToolCallsThrowsTimeout(): void
	{
		$frames = [];
		for ($i = 1; $i <= 4; $i++) {
			$frames[] = [
				['id' => 'call' . $i, 'type' => 'agent.custom_tool_use', 'name' => 'execute_sql', 'input' => []],
			];
		}
		$this->client->frames = $frames;

		$this->expectException(TurnTimeoutException::class);
		$this->runner(maxToolCalls: 3)->run($this->conversation('sesn_existing'), 'x', fn() => null);
	}

	public function testStuckExistingSessionIsRetriedWithAFreshOne(): void
	{
		$this->client->failOnUserMessage = true;
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Recovered']]],
			['id' => 'e2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$conversation = $this->conversation('sesn_stuck');
		$persisted = [];

		$result = $this->runner()->run($conversation, 'x', function (?string $id) use (&$persisted): void {
			$persisted[] = $id;
		});

		$this->assertSame('Recovered', $result->text);
		// The stale session id was cleared and the fresh one persisted.
		$this->assertSame([null, 'sesn_1'], $persisted);
		$this->assertSame('sesn_1', $conversation->getSessionId());
	}

	public function testFreshSessionFailureIsNotRetried(): void
	{
		$this->client->failAllUserMessages = true;

		$this->expectException(\ADT\AiChat\Exception\AiChatException::class);
		// No session id = the very first session is fresh; its failure propagates.
		$this->runner()->run($this->conversation(), 'x', fn() => null);
	}
}
