<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Pseudonymization\PiiFinding;
use ADT\AiChat\Pseudonymization\PiiSubjectResolver;

/** Subjects from arrays instead of a database. */
final class FakeSubjectResolver implements PiiSubjectResolver
{
	/**
	 * @param array<string, int> $subjects span, or "first last" of a name => subject id
	 * @param list<string> $readable spans that stay readable (employees)
	 * @param list<PiiFinding> $inText findings of findInText() when their span is in the text
	 * @param array<int, string> $labels subject id => full name
	 */
	public function __construct(
		public array $subjects = [],
		public array $readable = [],
		public array $inText = [],
		public array $labels = [],
	) {
	}

	public function resolve(PiiFinding $finding): int|string|null
	{
		$name = trim($finding->firstName . ' ' . $finding->lastName);

		return $this->subjects[$finding->text] ?? ($name !== '' ? $this->subjects[$name] ?? null : null);
	}

	public function isReadable(PiiFinding $finding): bool
	{
		return in_array($finding->text, $this->readable, true);
	}

	public function findInText(string $text): array
	{
		return array_values(array_filter($this->inText, static fn(PiiFinding $f): bool => str_contains($text, $f->text)));
	}

	public function label(int|string $subject): ?string
	{
		return $this->labels[(int) $subject] ?? null;
	}
}
