<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * The last line of defence over the chat output: redacts direct personal
 * identifiers from the final (full-text) reply of the model, should they slip
 * through despite the anonymized database and the system prompt.
 *
 * Two mechanisms:
 *  - built-in patterns for values a regex can detect reliably: e-mail addresses,
 *    phone numbers with an international prefix, IBAN and CZ/SK bank accounts,
 *    and secrets (JWT, long hex/base64 strings),
 *  - literal VALUE redaction for everything a regex cannot tell from ordinary
 *    text (names, birth dates): {@see filter()} receives the concrete values that
 *    appeared in personal-data columns of SQL results (see
 *    {@see \ADT\DoctrineAnonymization\PersonalDataColumns} and
 *    {@see PiiValueCollector}) and removes their literal occurrences.
 *
 * The replacement labels default to English; pass your own for a localized chat.
 */
class PiiResponseFilter
{
	private const DEFAULT_LABELS = [
		'secret' => '[redacted: secret token]',
		'email' => '[redacted e-mail]',
		'bank_account' => '[redacted bank account]',
		'phone' => '[redacted phone number]',
		'value' => '[redacted personal data]',
	];

	/** Shorter values are not redacted - too likely to hit ordinary words. */
	private const MIN_VALUE_LENGTH = 3;

	/** @var array<string, string> */
	private array $labels;

	/**
	 * @param array<string, string> $labels replacement labels; keys: secret, email,
	 *                                      bank_account, phone, value
	 * @param string $phonePrefixPattern regex fragment matching the international
	 *        prefixes to treat as phone numbers; the default covers CZ/SK. Kept
	 *        narrow on purpose - matching any number would destroy analytics.
	 */
	public function __construct(
		array $labels = [],
		private readonly string $phonePrefixPattern = '42[01]',
	) {
		$this->labels = $labels + self::DEFAULT_LABELS;
	}

	/**
	 * @param list<string> $piiValues concrete values from personal-data columns of
	 *                                 SQL results (names, birth dates, ...) to remove
	 */
	public function filter(string $text, array $piiValues = []): string
	{
		$text = $this->redactValues($text, $piiValues);

		foreach ($this->patterns() as [$pattern, $replacement]) {
			$text = (string) preg_replace($pattern, $replacement, $text);
		}

		return $text;
	}

	/**
	 * Patterns in order - more specific ones (token, IBAN) go before generic ones
	 * so they do not overlap.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function patterns(): array
	{
		return [
			// SECRET - JWT (three base64url segments separated by dots).
			['~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b~', $this->labels['secret']],
			// SECRET - long hex (>= 32) or base64-like string (>= 40) = token/password/hash.
			['~\b[0-9a-fA-F]{32,}\b~', $this->labels['secret']],
			['~\b[A-Za-z0-9+/]{40,}={0,2}\b~', $this->labels['secret']],

			// EMAIL.
			['~\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b~', $this->labels['email']],

			// BANK_ACCOUNT - IBAN (2 letters + 2 digits + 11-30 alphanumerics, optional spaces).
			['~\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b~', $this->labels['bank_account']],
			// BANK_ACCOUNT - CZ/SK format [prefix-]number/4-digit bank code. Either a
			// prefix or at least a 6-digit account number is required, so ordinary
			// "MM/YYYY" or "YYYY/YYYY" (e.g. "06/2024") is left alone.
			['~\b(?:\d{1,6}-\d{2,10}|\d{6,10})/\d{4}\b~', $this->labels['bank_account']],

			// PHONE - only with an international prefix, so ordinary numbers in
			// analytics are left alone.
			['~(?:\+|00)\s?' . $this->phonePrefixPattern . '\s?\d{2,3}(?:\s?\d{2,3}){2,3}\b~', $this->labels['phone']],
		];
	}

	/**
	 * Removes literal occurrences of the given values (case-insensitive, on word
	 * boundaries). Longer values first, so their parts are not redacted instead.
	 *
	 * @param list<string> $piiValues
	 */
	private function redactValues(string $text, array $piiValues): string
	{
		$values = [];
		foreach ($piiValues as $value) {
			$value = trim((string) $value);
			if (mb_strlen($value) >= self::MIN_VALUE_LENGTH) {
				$values[$value] = true;
			}
		}

		if (!$values) {
			return $text;
		}

		$unique = array_keys($values);
		usort($unique, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

		// One preg_replace over an alternation of all values instead of N passes.
		$alternation = implode('|', array_map(static fn(string $v): string => preg_quote($v, '~'), $unique));

		return (string) preg_replace(
			'~(?<![\p{L}\p{N}])(?:' . $alternation . ')(?![\p{L}\p{N}])~iu',
			$this->labels['value'],
			$text,
		);
	}
}
