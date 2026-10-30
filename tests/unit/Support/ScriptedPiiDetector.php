<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Pseudonymization\OpenAiCompatiblePiiDetector;

/** The detector answering every text with $response (the raw content of the model reply), or failing with it. */
final class ScriptedPiiDetector extends OpenAiCompatiblePiiDetector
{
	/** @var list<string> */
	public array $texts = [];

	public function __construct(private readonly string|\Throwable $response, bool $required = false)
	{
		parent::__construct('http://detector.test', 'model', required: $required);
	}

	/** @param list<array<string, string>> $items */
	public static function returning(array $items, bool $required = false): self
	{
		return new self(json_encode(['items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $required);
	}

	protected function complete(string $text): string
	{
		$this->texts[] = $text;

		return is_string($this->response) ? $this->response : throw $this->response;
	}
}
