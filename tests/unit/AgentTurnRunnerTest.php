<?php

declare(strict_types=1);

use ADT\AiChat\AgentResolution;
use ADT\AiChat\AgentTurnRunner;
use ADT\AiChat\AiChatOptions;
use ADT\AiChat\Exception\TurnTimeoutException;
use ADT\AiChat\Tests\Support\FakeConversation;
use ADT\AiChat\Tests\Support\FakeManagedAgentsClient;
use ADT\AiChat\ToolExecutor;
use ADT\AiChat\TurnMonitor;

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

	private function runner(float $idleTimeout = 1.0, int $maxToolCalls = 5, float $maxTurnSeconds = 60): AgentTurnRunner
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
			new AiChatOptions(0, $idleTimeout, $maxToolCalls, maxTurnSeconds: $maxTurnSeconds, interruptGraceSeconds: 0.2),
		);
	}

	private function toolExecutor(): ToolExecutor
	{
		return new class implements ToolExecutor {
			public function handleToolCall(string $toolName, array $input): array
			{
				return ['result' => [], 'row_count' => 0];
			}

			public function getCustomToolDefinitions(): array
			{
				return [];
			}
		};
	}

	/**
	 * @param list<array<string, mixed>> $progress collects every progress report
	 */
	private function monitor(bool &$cancelled = false, array &$progress = []): object
	{
		return new class ($cancelled, $progress) implements TurnMonitor {
			/** @var (callable(): void)|null */
			public $onFirstProgress = null;

			public function __construct(private bool &$cancelled, private array &$progress)
			{
			}

			public function isCancelled(): bool
			{
				return $this->cancelled;
			}

			public function onProgress(array $progress): void
			{
				$this->progress[] = $progress;
				if ($this->onFirstProgress !== null) {
					($this->onFirstProgress)();
					$this->onFirstProgress = null;
				}
			}
		};
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

	public function testAttachmentsBecomeContentBlocksBeforeTheText(): void
	{
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Read it']]],
			['id' => 'e2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$this->runner()->run($this->conversation(), 'What is wrong in this export?', fn() => null, [
			new \ADT\AiChat\Attachment('file_img', 'image/png', 'screenshot.png'),
			new \ADT\AiChat\Attachment('file_pdf', 'application/pdf', 'report.pdf'),
			new \ADT\AiChat\Attachment('file_txt', 'text/plain', 'export.csv'),
		]);

		$content = $this->client->sentEvents[0][0]['content'];
		$this->assertSame([
			['type' => 'image', 'source' => ['type' => 'file', 'file_id' => 'file_img']],
			['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'file_pdf']],
			['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'file_txt']],
			['type' => 'text', 'text' => 'What is wrong in this export?'],
		], $content);
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

	public function testQuietSessionThatNoLongerRunsTimesOut(): void
	{
		// No terminal event ever arrives and the session says it is not running.
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'partial']]],
		]];

		try {
			$this->runner(idleTimeout: 0.05)->run($this->conversation('sesn_existing'), 'x', fn() => null);
			$this->fail('A timeout was expected.');
		} catch (TurnTimeoutException $e) {
			$this->assertSame('aiChat.error.turnTimeout', $e->getTranslationKey());
		}

		$this->assertSame(1, $this->client->retrieveCalls);
		// The session is stopped, so the next message is not refused.
		$this->assertSame('user.interrupt', end($this->client->sentEvents)[0]['type']);
	}

	public function testQuietButRunningSessionKeepsTheTurnAlive(): void
	{
		// A long model request: nothing new for a while, the session reports running.
		$this->client->sessionStatuses = array_fill(0, 100, 'running');
		$frames = [[['id' => 'e1', 'type' => 'session.status_running']]];
		for ($i = 0; $i < 30; $i++) {
			$frames[] = [];
		}
		$frames[] = [
			['id' => 'e2', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Finally']]],
			['id' => 'e3', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		];
		$this->client->frames = $frames;

		$progress = [];
		$monitor = $this->monitor(progress: $progress);
		// Every poll sleeps 0 s - slow it down so the idle timeout kicks in in between.
		$runner = new AgentTurnRunner(
			$this->client,
			$this->toolExecutor(),
			fn(): AgentResolution => new AgentResolution('agent_1', 'env_1'),
			new AiChatOptions(2000, 0.02, 5),
		);

		$result = $runner->run($this->conversation('sesn_existing'), 'x', fn() => null, monitor: $monitor);

		$this->assertSame('Finally', $result->text);
		$this->assertGreaterThan(0, $this->client->retrieveCalls);
		// The confirmed running session counts as a sign of life too.
		$this->assertGreaterThan(2, count($progress));
	}

	public function testMaxTurnSecondsIsAHardCap(): void
	{
		$this->client->sessionStatuses = array_fill(0, 1000, 'running');
		$this->client->frames = [[['id' => 'e1', 'type' => 'session.status_running']]];

		$this->expectException(TurnTimeoutException::class);
		$this->runner(idleTimeout: 0.01, maxTurnSeconds: 0.1)->run($this->conversation('sesn_existing'), 'x', fn() => null);
	}

	public function testLongSessionIsReadFromTheNewestEventsBack(): void
	{
		// More history than one page - reading from the start would never get to the reply.
		for ($i = 1; $i <= 250; $i++) {
			$this->client->history[] = ['id' => 'old' . $i, 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'OLD']]];
		}
		$this->client->maxPageSize = 20;
		$this->client->frames = [
			array_map(static fn(int $i): array => ['id' => 'n' . $i, 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'part' . $i]]], range(1, 45)),
			[['id' => 'idle', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']]],
		];

		$result = $this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null);

		// All 45 new events in order, over three pages, none of the history.
		$this->assertSame(implode("\n", array_map(static fn(int $i): string => 'part' . $i, range(1, 45))), $result->text);
		$this->assertSame('desc', $this->client->listRequests[0]['order']);
		// Paging stopped at the first seen event, the history was never read through.
		$this->assertLessThan(10, count($this->client->listRequests));
	}

	public function testCancelInterruptsTheSessionAndReturnsWhatThereIs(): void
	{
		$this->client->frames = [
			[['id' => 'm1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Working on it']]]],
			[['id' => 'm2', 'type' => 'session.status_running']],
			[['id' => 's1', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']]],
		];
		$cancelled = false;
		$progress = [];
		$monitor = $this->monitor($cancelled, $progress);
		$monitor->onFirstProgress = function () use (&$cancelled): void {
			$cancelled = true;
		};

		$result = $this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null, monitor: $monitor);

		$this->assertTrue($result->interrupted);
		$this->assertSame('Working on it', $result->text);
		$types = array_map(static fn(array $events): string => $events[0]['type'], $this->client->sentEvents);
		$this->assertSame(['user.message', 'user.interrupt'], $types);
	}

	public function testCancelledBeforeStartDoesNotSendTheMessage(): void
	{
		$cancelled = true;
		$result = $this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null, monitor: $this->monitor($cancelled));

		$this->assertTrue($result->interrupted);
		$this->assertSame([], $this->client->sentEvents);
	}

	public function testSubagentsWorkInThreadsOfTheirOwn(): void
	{
		$this->client->frames = [
			[
				['id' => 'c1', 'type' => 'session.thread_created', 'session_thread_id' => 'th_a', 'agent_name' => 'ai-chat (test)'],
				['id' => 'c2', 'type' => 'agent.thread_message_sent', 'to_session_thread_id' => 'th_a', 'to_agent_name' => 'ai-chat (test)', 'content' => [['type' => 'text', 'text' => 'Count clients in 2025']]],
				['id' => 'c3', 'type' => 'session.thread_status_running', 'session_thread_id' => 'th_a', 'agent_name' => 'ai-chat (test)'],
				// The subagent's tool call is cross-posted with its thread id.
				['id' => 't1', 'type' => 'agent.custom_tool_use', 'session_thread_id' => 'th_a', 'name' => 'execute_sql', 'input' => ['sql' => 'SELECT 1']],
				['id' => 'u1', 'type' => 'span.model_request_end', 'session_thread_id' => 'th_a', 'model_usage' => ['input_tokens' => 5000, 'output_tokens' => 10]],
				// The session reports idle while the subagent still works - not the end.
				['id' => 'i0', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
			],
			[
				['id' => 'c4', 'type' => 'agent.thread_message_received', 'from_session_thread_id' => 'th_a', 'from_agent_name' => 'ai-chat (test)', 'content' => [['type' => 'text', 'text' => '42 clients']]],
				['id' => 'c5', 'type' => 'session.thread_status_idle', 'session_thread_id' => 'th_a', 'agent_name' => 'ai-chat (test)', 'stop_reason' => ['type' => 'end_turn']],
				['id' => 'm1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'There were 42 clients.']]],
				['id' => 'u2', 'type' => 'span.model_request_end', 'model_usage' => ['input_tokens' => 900, 'output_tokens' => 20]],
			],
			[
				['id' => 'i1', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
			],
		];
		$progress = [];

		$result = $this->runner()->run($this->conversation('sesn_existing'), 'x', fn() => null, monitor: $this->monitor(progress: $progress));

		$this->assertSame('There were 42 clients.', $result->text);
		// The window is the main thread's; output counts everybody.
		$this->assertSame(900, $result->tokensInput);
		$this->assertSame(30, $result->tokensOutput);

		// The subagent entry first (placed when the task was sent), then its tool call.
		$this->assertSame([
			'tool_name' => 'subagent',
			'tool_input' => ['thread' => 'th_a', 'name' => 'ai-chat (test)', 'task' => 'Count clients in 2025'],
			'tool_result' => ['status' => 'idle', 'report' => '42 clients'],
		], $result->toolData[0]);
		$this->assertSame('execute_sql', $result->toolData[1]['tool_name']);
		$this->assertSame('th_a', $result->toolData[1]['thread']);

		// The tool result echoes the thread id.
		$toolResult = array_values(array_filter(array_merge(...$this->client->sentEvents), static fn(array $e): bool => $e['type'] === 'user.custom_tool_result'))[0];
		$this->assertSame('th_a', $toolResult['session_thread_id']);

		$this->assertSame(['id' => 'th_a', 'name' => 'ai-chat (test)', 'status' => 'running', 'task' => 'Count clients in 2025', 'tool_calls' => 1], $progress[0]['threads'][0]);
		$this->assertSame(0, $progress[0]['tool_calls']);
	}

	public function testToolCallBudgetIsPerThread(): void
	{
		$frames = [[
			['id' => 'c1', 'type' => 'agent.thread_message_sent', 'to_session_thread_id' => 'th_a', 'to_agent_name' => 'a', 'content' => 'task A'],
			['id' => 'c2', 'type' => 'agent.thread_message_sent', 'to_session_thread_id' => 'th_b', 'to_agent_name' => 'b', 'content' => 'task B'],
		]];
		foreach (['th_a', 'th_b'] as $thread) {
			for ($i = 1; $i <= 3; $i++) {
				$frames[] = [['id' => $thread . $i, 'type' => 'agent.custom_tool_use', 'session_thread_id' => $thread, 'name' => 'execute_sql', 'input' => []]];
			}
			$frames[] = [['id' => $thread . 'idle', 'type' => 'session.thread_status_idle', 'session_thread_id' => $thread]];
		}
		$frames[] = [['id' => 'idle', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']]];
		$this->client->frames = $frames;

		// 6 calls in total, 3 per thread - within a budget of 3.
		$result = $this->runner(maxToolCalls: 3)->run($this->conversation('sesn_existing'), 'x', fn() => null);

		$this->assertCount(8, $result->toolData);
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

	public function testHistoryGoesOnlyToANewSession(): void
	{
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Ok']]],
			['id' => 'e2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];
		$this->runner()->run($this->conversation(), 'x', fn() => null, [], fn(): string => 'HISTORY');

		$this->assertSame([['type' => 'text', 'text' => 'HISTORY'], ['type' => 'text', 'text' => 'x']], $this->client->sentEvents[0][0]['content']);
	}

	public function testExistingSessionGetsNoHistory(): void
	{
		$this->client->frames = [[
			['id' => 'e3', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Ok']]],
			['id' => 'e4', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];
		$this->runner()->run($this->conversation('sesn_existing'), 'y', fn() => null, [], function (): string {
			$this->fail('An existing session already has the history.');
		});

		$this->assertSame([['type' => 'text', 'text' => 'y']], end($this->client->sentEvents)[0]['content']);
	}

	public function testStuckSessionReplacementGetsTheHistory(): void
	{
		$this->client->failOnUserMessage = true;
		$this->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Recovered']]],
			['id' => 'e2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$this->runner()->run($this->conversation('sesn_stuck'), 'x', fn() => null, [], fn(): string => 'HISTORY');

		$this->assertSame([['type' => 'text', 'text' => 'HISTORY'], ['type' => 'text', 'text' => 'x']], end($this->client->sentEvents)[0]['content']);
	}

	public function testFreshSessionFailureIsNotRetried(): void
	{
		$this->client->failAllUserMessages = true;

		$this->expectException(\ADT\AiChat\Exception\AiChatException::class);
		// No session id = the very first session is fresh; its failure propagates.
		$this->runner()->run($this->conversation(), 'x', fn() => null);
	}
}
