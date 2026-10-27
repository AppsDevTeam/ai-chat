<?php

declare(strict_types=1);

namespace App\AiChat\Ui;

use Nette\Localization\Translator;

/**
 * Adapter of ChatService::setTranslator() to the project translator. Missing keys
 * fall back to the English default of the package.
 */
class ChatTranslator
{
	public function __construct(
		private readonly Translator $translator,
	) {
	}

	/**
	 * @param array<string, scalar> $params
	 */
	public function translate(string $key, array $params, string $default): string
	{
		$translated = (string) $this->translator->translate($key, $params);

		return $translated !== $key ? $translated : $default;
	}
}
