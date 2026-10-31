<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Tuning of the agent turn loop.
 *
 * A turn has no fixed length: it runs as long as the session keeps producing
 * events. Only a session that stays quiet for {@see $idleTimeoutSeconds} AND is no
 * longer running on Anthropic's side counts as lost; {@see $maxTurnSeconds} is a
 * hard cap against a turn that never ends.
 */
class AiChatOptions
{
	/**
	 * @param int $pollIntervalMicroseconds pause between event polls; the worker
	 *                                      polls instead of holding an SSE stream
	 * @param float $idleTimeoutSeconds without a new event for this long the session
	 *                                  status is checked - a running session (a long
	 *                                  model request) keeps the turn alive, anything
	 *                                  else ends it as timed out
	 * @param int $maxToolCalls hard stop against a runaway agent - per thread, so
	 *                          every subagent has its own budget
	 * @param int $systemPromptMaxLength hard limit of the agent `system` field
	 *                                   imposed by the API
	 * @param float $maxTurnSeconds hard cap of one turn, however busy the session is
	 * @param float $interruptGraceSeconds how long a cancelled turn waits for the
	 *                                     session to confirm it stopped
	 */
	public function __construct(
		public readonly int $pollIntervalMicroseconds = 500000,
		public readonly float $idleTimeoutSeconds = 300,
		public readonly int $maxToolCalls = 25,
		public readonly int $systemPromptMaxLength = 100000,
		public readonly float $maxTurnSeconds = 3600,
		public readonly float $interruptGraceSeconds = 30,
	) {
	}
}
