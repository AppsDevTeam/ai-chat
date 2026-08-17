<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Tuning of the agent turn loop.
 */
class AiChatOptions
{
	/**
	 * @param int $pollIntervalMicroseconds pause between event polls; the worker
	 *                                      polls instead of holding an SSE stream
	 * @param int $maxPolls upper bound of polls per turn (~4 min at 0.5 s); the
	 *                      turn runs in a worker, so a longer budget only reduces
	 *                      "did not finish in time" failures
	 * @param int $maxToolCalls hard stop against a runaway agent within one turn
	 * @param int $systemPromptMaxLength hard limit of the agent `system` field
	 *                                   imposed by the API
	 */
	public function __construct(
		public readonly int $pollIntervalMicroseconds = 500000,
		public readonly int $maxPolls = 480,
		public readonly int $maxToolCalls = 25,
		public readonly int $systemPromptMaxLength = 100000,
	) {
	}
}
