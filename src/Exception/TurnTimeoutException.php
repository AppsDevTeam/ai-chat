<?php

declare(strict_types=1);

namespace ADT\AiChat\Exception;

/**
 * The agent turn did not reach a terminal state in time, or made too many tool
 * calls and was stopped.
 *
 * Distinct from {@see AiChatException} on purpose: a timed-out turn must NOT be
 * retried with a fresh session - the user message has already been delivered to
 * the existing session and re-sending it would duplicate it. Let the user send
 * the next message over the same (kept) session instead.
 */
class TurnTimeoutException extends AiChatException
{
}
