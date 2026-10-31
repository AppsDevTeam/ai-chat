<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * {@see PiiDetector} over an OpenAI-compatible chat completions API
 * (`POST {url}/v1/chat/completions`) - meant for a model on your own
 * infrastructure (qwen, llama ...), so the text does not leave it. Needs
 * `guzzlehttp/guzzle`.
 *
 * The model returns the exact spans with their kind, names also in the base form
 * the subject is looked up by. Spans that are not in the text (hallucinations) or
 * shorter than 3 characters are dropped, an unknown kind becomes `other`.
 *
 * Without a URL and model, or when the request fails or the reply holds no JSON:
 * with $required a {@see PiiCheckUnavailableException} (the text must not go to the
 * AI), otherwise null - the pseudonymizer then relies on its patterns and the
 * resolver (e.g. for local development without the model).
 */
class OpenAiCompatiblePiiDetector implements PiiDetector
{
	public const DEFAULT_SYSTEM_PROMPT = <<<'TXT'
		You check a text for personal data. Find every piece of data in the text that identifies
		a specific person. Reply ONLY with a JSON object:
		{"items": [{"text": "exact span of the text", "kind": "kind", "first_name": "", "last_name": ""}]}
		Kinds:
		- name: first or last name of a person (also lower case, in any grammatical form); put
		  first_name and last_name in their base form, "" when the first name is missing
		- birth_number: national identification number
		- insurance_number: health insurance number
		- file_number: file or record number of a client
		- phone: phone number
		- email: e-mail address
		- address: home address (street with a number; not a city or postcode alone)
		- birth_date: date of birth of a person (not a date of an appointment, order or period)
		- bank_account: bank account number or IBAN
		- document_number: number of an ID card, passport or other document
		- other: other data identifying a specific person (user name, licence plate...)
		Rules:
		- "text" must be a literal span of the given text (incl. inflection, diacritics and spaces), without surrounding words.
		- Leave first_name and last_name empty for other kinds than name.
		- Do not return titles or salutations, names of companies, services, drugs, diagnoses or places.
		- Health data (diagnosis, treatment) alone does not identify a person - do not return it.
		- When there is no such data in the text, return {"items": []}.
		TXT;

	/** Shorter spans make no sense as personal data and would hit innocent abbreviations. */
	private const MIN_LENGTH = 3;

	public function __construct(
		private readonly ?string $url,
		private readonly ?string $model,
		private readonly ?string $apiKey = null,
		private readonly ?string $authUser = null,
		private readonly ?string $authPassword = null,
		private readonly bool $required = true,
		private readonly int $timeout = 20,
		private readonly ?string $systemPrompt = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->url !== null && $this->url !== '' && $this->model !== null && $this->model !== '';
	}

	public function detect(string $text): ?array
	{
		if (!$this->isEnabled()) {
			if ($this->required) {
				throw PiiCheckUnavailableException::create('The personal data check is required, but the detector is not configured (url, model).');
			}
			return null;
		}

		try {
			$content = $this->complete($text);
			// Models sometimes wrap the JSON in a sentence or ```json - take the first { to the last }
			$decoded = preg_match('~\{.*}~s', $content, $m) ? json_decode($m[0], true) : null;
			if (!is_array($decoded)) {
				// Never the reply itself in the message - it may contain the user's text (goes to the log)
				throw new \UnexpectedValueException('The detector did not return JSON.');
			}
		} catch (\Throwable $e) {
			if ($this->required) {
				throw PiiCheckUnavailableException::create('The personal data check failed: ' . $e->getMessage(), $e);
			}
			$this->logger?->warning('The personal data check failed: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		$findings = [];
		foreach (is_array($decoded['items'] ?? null) ? $decoded['items'] : [] as $item) {
			$span = is_array($item) ? trim((string) ($item['text'] ?? '')) : '';
			// A span that is not in the text could not be replaced anyway
			if (mb_strlen($span) < self::MIN_LENGTH || !str_contains($text, $span)) {
				continue;
			}
			$kind = (string) ($item['kind'] ?? '');
			$findings[] = new PiiFinding(
				$span,
				in_array($kind, PiiFinding::KINDS, true) ? $kind : PiiFinding::OTHER,
				trim((string) ($item['first_name'] ?? '')),
				trim((string) ($item['last_name'] ?? '')),
			);
		}

		return $findings;
	}

	/** The raw reply of the model to the user's text (tests replace it). */
	protected function complete(string $text): string
	{
		$options = [
			'timeout' => $this->timeout,
			'headers' => ['X-Api-Key' => (string) $this->apiKey],
			'json' => [
				'model' => $this->model,
				'temperature' => 0,
				'response_format' => ['type' => 'json_object'],
				'messages' => [
					['role' => 'system', 'content' => $this->systemPrompt ?? self::DEFAULT_SYSTEM_PROMPT],
					['role' => 'user', 'content' => $text],
				],
			],
		];
		if ($this->authUser) {
			$options['auth'] = [$this->authUser, (string) $this->authPassword];
		}
		$response = (new Client())->post(rtrim((string) $this->url, '/') . '/v1/chat/completions', $options);
		$data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		return (string) ($data['choices'][0]['message']['content'] ?? '');
	}
}
