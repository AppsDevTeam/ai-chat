<?php

declare(strict_types=1);

namespace ADT\AiChat\Exception;

/**
 * Base failure of the chat engine. The message is safe to show to the user.
 *
 * The English message is the default text. Failures the user is expected to see
 * also carry a translation key with parameters ({@see translatable()}), so a
 * localized UI can show its own wording - see {@see \ADT\AiChat\ChatService} and
 * {@see \ADT\AiChat\Http\ChatEndpoint}, which take an optional translator.
 */
class AiChatException extends \RuntimeException
{
	private ?string $translationKey = null;

	/** @var array<string, scalar> */
	private array $translationParams = [];

	/**
	 * @param array<string, scalar> $params placeholder values, also already
	 *        substituted in $message
	 */
	public static function translatable(string $message, string $key, array $params = [], ?\Throwable $previous = null): static
	{
		$exception = new static($message, 0, $previous);
		$exception->translationKey = $key;
		$exception->translationParams = $params;

		return $exception;
	}

	/** E.g. `aiChat.error.turnTimeout`; null when the message has no translation. */
	public function getTranslationKey(): ?string
	{
		return $this->translationKey;
	}

	/**
	 * @return array<string, scalar>
	 */
	public function getTranslationParams(): array
	{
		return $this->translationParams;
	}
}
