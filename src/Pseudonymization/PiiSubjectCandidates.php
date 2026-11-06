<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/**
 * Optional for a {@see PiiSubjectResolver}: the subjects a finding may belong to
 * when {@see PiiSubjectResolver::resolve()} cannot pick one (two patients of the
 * same name). The note for the AI lists them by their anchors, so the AI asks the
 * user which one is meant instead of not knowing who it is.
 */
interface PiiSubjectCandidates
{
	/**
	 * A few subjects the finding may belong to (an empty list when there are none,
	 * or too many to ask about); asked only for findings without a subject.
	 *
	 * @return list<int|string>
	 */
	public function candidates(PiiFinding $finding): array;
}
