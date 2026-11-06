<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/**
 * Optional for a {@see PiiSubjectResolver}: where the subject's record is (the
 * patient card ...). The restored reply then shows the subject as a markdown link
 * `[label](url)`; the AI never sees the link.
 */
interface PiiSubjectLinker
{
	/** The URL of the subject's record for the current user; null = plain label. */
	public function url(int|string $subject): ?string;
}
