<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Lets the caller watch and stop a running turn ({@see AgentTurnRunner::run()}).
 *
 * Both methods are called from the poll loop, i.e. every poll interval - keep them
 * cheap (throttle database access on your side).
 */
interface TurnMonitor
{
	/**
	 * Has the user asked to stop the turn? The runner then interrupts the session
	 * and returns what the agent managed so far as an interrupted result.
	 */
	public function isCancelled(): bool;

	/**
	 * Called after every poll that brought new events - a heartbeat of the turn.
	 *
	 * @param array{tool_calls: int, threads: list<array{id: string, name: string, status: string, task: string, tool_calls: int}>} $progress
	 *        tool calls of the main thread and the subagent threads started so far
	 */
	public function onProgress(array $progress): void;
}
