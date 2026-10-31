<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use ADT\AiChat\Exception\AiChatException;

/**
 * The required personal data check did not run (the detector is not configured or
 * failed) - the user's text must not go to the AI. {@see \ADT\AiChat\ChatService}
 * stores the message (key `aiChat.error.piiCheckUnavailable`) as an error reply.
 *
 * The technical reason ({@see getReason()}) is for the log only; it never contains
 * the user's text or the detector's reply.
 */
class PiiCheckUnavailableException extends AiChatException
{
	public const TRANSLATION_KEY = 'aiChat.error.piiCheckUnavailable';

	public const USER_MESSAGE = 'The message was not sent to the AI - the personal data check is unavailable right now. Please try again in a moment.';

	public static function create(string $reason, ?\Throwable $previous = null): static
	{
		return static::translatable(self::USER_MESSAGE, self::TRANSLATION_KEY, [], new \RuntimeException($reason, 0, $previous));
	}

	public function getReason(): string
	{
		return $this->getPrevious()?->getMessage() ?? $this->getMessage();
	}
}
