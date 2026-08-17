<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Outcome of one agent turn.
 */
class TurnResult
{
	/**
	 * @param string $text concatenated text blocks of the agent reply
	 * @param list<array{tool_name: string, tool_input: array<string, mixed>, tool_result: array<string, mixed>}> $toolData
	 *        every tool call made during the turn, with its input and result
	 * @param int $tokensInput size of the context window used by the LAST request
	 *        of the turn (input + cache read + cache creation) - every request
	 *        resends the whole growing context, so the last one equals the current
	 *        window usage; summing them would overcount ~N times
	 * @param int $tokensOutput total output tokens of the turn (for billing)
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $toolData,
		public readonly int $tokensInput,
		public readonly int $tokensOutput,
	) {
	}
}
