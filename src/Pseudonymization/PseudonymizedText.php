<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/** The result of {@see Pseudonymizer::pseudonymize()}. */
final class PseudonymizedText
{
	/**
	 * @param string $forAi the text for the AI: anchors plus the note on what they mean
	 * @param string|null $replaced the text with anchors without the note - shown to
	 *        the user as what the AI received; null = nothing was replaced
	 * @param array<string, int> $findings anchor kind (the subject label, PERSON,
	 *        ADDRESS ...) => number of replaced spans; counts only, never values
	 * @param bool $detectorChecked the detector checked the text (otherwise the
	 *        patterns and the resolver only)
	 */
	public function __construct(
		public readonly string $forAi,
		public readonly ?string $replaced,
		public readonly array $findings,
		public readonly bool $detectorChecked,
	) {
	}
}
