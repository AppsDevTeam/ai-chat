<?php

declare(strict_types=1);

namespace ADT\AiChat\Exception;

/**
 * The Managed Agents or Files API could not be reached or answered with an error.
 *
 * A subclass of {@see AiChatException} on purpose: {@see \ADT\AiChat\AgentTurnRunner}
 * reads it as "the EXISTING session refused the message" (expired, deleted, stuck
 * in requires_action) and retries once with a fresh session. Throw it from your own
 * {@see \ADT\AiChat\Client\ManagedAgentsClient} implementation too.
 *
 * The message only carries a short detail (HTTP status), never the URL, headers or
 * response body - it is shown to the user. The full failure is in $previous.
 */
class ApiUnavailableException extends AiChatException
{
	public static function fromDetail(string $detail, ?\Throwable $previous = null): static
	{
		return static::translatable(
			'The AI service is temporarily unavailable. Please try again in a moment. (' . $detail . ')',
			'aiChat.error.serviceUnavailable',
			['detail' => $detail],
			$previous,
		);
	}
}
