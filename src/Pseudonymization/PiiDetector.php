<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/**
 * Finds personal data a regex cannot recognise (names in any form, addresses,
 * birth dates, document numbers ...) - typically a language model running on your
 * own infrastructure, so the text never leaves it.
 */
interface PiiDetector
{
	/**
	 * @return list<PiiFinding>|null null = the check did not run and is not required
	 *         (the pseudonymizer then relies on the patterns and the resolver only)
	 * @throws PiiCheckUnavailableException the check is required and did not run
	 */
	public function detect(string $text): ?array;
}
