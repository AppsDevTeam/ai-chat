<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

use ADT\AiChat\Entity\ConversationInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pseudonymizes the user's text before it goes to the AI - the input counterpart of
 * {@see \ADT\AiChat\PiiResponseFilter}. The anonymized views protect the data in
 * the database, not what the user types ("Text Jane Doe, born 1.2.1980").
 *
 * Personal data is replaced with anchors:
 *  - data that belongs to exactly one subject (patient, client ...) according to the
 *    {@see PiiSubjectResolver} → [SUBJECT #123] - the id is not personal data, the AI
 *    uses it in its tools right away,
 *  - anything else → [PERSON_1], [EMAIL_1], [ADDRESS_1] ...; the value is stored in
 *    the conversation's pseudonyms (your entity with {@see PseudonymTrait}), the same
 *    value gets the same anchor within the conversation.
 *
 * Data is found by patterns (birth numbers, e-mails, phones, bank accounts - work
 * without the detector), by the {@see PiiDetector} and by the resolver's own
 * knowledge ({@see PiiSubjectResolver::findInText()}). Only whole spans are
 * replaced - a letter at the edge of a span must not continue a word, a digit a
 * number; longer spans win.
 *
 * This is not anonymization (the context stays), but the personal data does not
 * leave your side. {@see restore()} puts the real values back into the reply - only
 * your user sees them.
 */
class Pseudonymizer
{
	public const DEFAULT_LABELS = [
		PiiFinding::NAME => 'PERSON',
		PiiFinding::BIRTH_NUMBER => 'BIRTH_NUMBER',
		PiiFinding::INSURANCE_NUMBER => 'INSURANCE_NUMBER',
		PiiFinding::FILE_NUMBER => 'FILE_NUMBER',
		PiiFinding::PHONE => 'PHONE',
		PiiFinding::EMAIL => 'EMAIL',
		PiiFinding::ADDRESS => 'ADDRESS',
		PiiFinding::BIRTH_DATE => 'BIRTH_DATE',
		PiiFinding::BANK_ACCOUNT => 'BANK_ACCOUNT',
		PiiFinding::DOCUMENT_NUMBER => 'DOCUMENT',
		PiiFinding::OTHER => 'DATA',
	];

	public const DEFAULT_SUBJECT_ANCHOR = '[SUBJECT #%s]';

	public const DEFAULT_NOTE = '[System note: personal data in the text is replaced with anchors. '
		. '%1$s is the data subject with id 123 - use this id in SQL and in tools and write the anchor '
		. 'in your reply too. [PERSON_1], [EMAIL_1], [ADDRESS_1] and other anchors of the form [KIND_number] '
		. 'are hidden data the system did not assign to one subject - write the anchor in your reply, the user '
		. 'will see the original data instead; when you need the data for the task, ask the user to clarify.]';

	/**
	 * Patterns in order: [kind, pattern, exclude (a match of it is not this kind)].
	 * The birth number goes first, a phone would eat it otherwise (9-10 digits). The
	 * birth number must not be a part of a bank account (19-2000145399/0800) and the
	 * account must not take the shape of a birth number (885512/3457); MM/YYYY is not
	 * an account.
	 */
	public const DEFAULT_PATTERNS = [
		[PiiFinding::BIRTH_NUMBER, '~(?<![\d/-])(\d{6})\s*/?\s*(\d{3,4})(?![\d/])~u', null],
		[PiiFinding::EMAIL, '~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', null],
		[PiiFinding::PHONE, '~(?<![\d+])(?:\+?42[01][\s-]?)?\d{3}[\s-]?\d{3}[\s-]?\d{3}(?!\d)~u', null],
		[PiiFinding::BANK_ACCOUNT, '~(?<![\d/-])(?:\d{1,6}-\d{2,10}|\d{6,10})/\d{4}(?![\d/])~u', '~^\d{6}/\d{3,4}$~'],
		[PiiFinding::BANK_ACCOUNT, '~(?<![A-Z\d])(?:CZ|SK)\d{2}(?:\s?\d{4}){5}(?!\d)~u', null],
	];

	/** @var array<string, string> */
	private array $labels;

	private string $subjectLabel;

	private string $note;

	/**
	 * @param class-string<PseudonymInterface> $pseudonymClass
	 * @param string $subjectAnchor the anchor of a subject, `%s` = its id
	 * @param array<string, string> $labels finding kind => anchor name ([PERSON_1])
	 * @param string|null $note appended to the text for the AI when something was
	 *        replaced, explains the anchors; `%1$s` = an example subject anchor
	 * @param list<array{0: string, 1: string, 2: string|null}>|null $patterns see {@see DEFAULT_PATTERNS}
	 * @param string $conversationField association of the pseudonym to its conversation
	 */
	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly string $pseudonymClass,
		private readonly ?PiiDetector $detector = null,
		private readonly ?PiiSubjectResolver $resolver = null,
		private readonly string $subjectAnchor = self::DEFAULT_SUBJECT_ANCHOR,
		array $labels = [],
		?string $note = null,
		private readonly ?array $patterns = null,
		private readonly string $conversationField = 'conversation',
	) {
		if (substr_count($subjectAnchor, '%s') !== 1) {
			throw new \InvalidArgumentException("The subject anchor must contain %s exactly once, '$subjectAnchor' given.");
		}
		$this->labels = $labels + self::DEFAULT_LABELS;
		$this->subjectLabel = preg_match('~^\W*([^\s#%\]]+)~u', $subjectAnchor, $m) ? $m[1] : 'SUBJECT';
		$this->note = "\n\n" . sprintf($note ?? self::DEFAULT_NOTE, sprintf($subjectAnchor, '123'));
	}

	/** @throws PiiCheckUnavailableException the required check did not run - the text must not go to the AI */
	public function pseudonymize(ConversationInterface $conversation, string $text): PseudonymizedText
	{
		// The detector first: when the required check fails, no anchor exists yet
		$detected = $this->detector?->detect($text);

		/** @var array<string, array{0: string, 1: string}> $spans span => [anchor, label]; numeric spans become int keys */
		$spans = [];
		foreach ($this->patterns ?? self::DEFAULT_PATTERNS as [$kind, $pattern, $exclude]) {
			preg_match_all($pattern, $text, $matches);
			foreach ($matches[0] as $span) {
				if (!isset($spans[$span]) && ($exclude === null || !preg_match($exclude, $span))) {
					$this->add($spans, $conversation, new PiiFinding($span, $kind), checkReadable: true);
				}
			}
		}
		foreach ($detected ?? [] as $finding) {
			if (!isset($spans[$finding->text])) {
				$this->add($spans, $conversation, $finding, checkReadable: true);
			}
		}
		foreach ($this->resolver?->findInText($text) ?? [] as $finding) {
			if (!isset($spans[$finding->text])) {
				$this->add($spans, $conversation, $finding, checkReadable: false);
			}
		}

		if ($spans === []) {
			return new PseudonymizedText($text, null, [], $detected !== null);
		}
		$this->em->flush();

		$anchors = [];
		$findings = [];
		foreach ($spans as $span => [$anchor, $label]) {
			$anchors[(string) $span] = $anchor;
			$findings[$label] = ($findings[$label] ?? 0) + 1;
		}
		ksort($findings);

		// Longer spans first - a name with a birth number inside is not replaced by halves
		$surfaces = array_map('strval', array_keys($anchors));
		usort($surfaces, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
		$pattern = '~' . implode('|', array_map(self::wholeSpan(...), $surfaces)) . '~u';
		$replaced = (string) preg_replace_callback($pattern, static fn(array $m): string => $anchors[$m[0]] ?? $m[0], $text);

		return new PseudonymizedText($replaced . $this->note, $replaced, $findings, $detected !== null);
	}

	/** Anchors in the reply back to the real values - for display on your side only. */
	public function restore(ConversationInterface $conversation, string $text): string
	{
		if ($this->resolver !== null) {
			[$before, $after] = explode('%s', $this->subjectAnchor);
			$text = (string) preg_replace_callback(
				'~' . preg_quote($before, '~') . '([^\s\]]+?)' . preg_quote($after, '~') . '~u',
				fn(array $m): string => $this->resolver->label($m[1]) ?? $m[0],
				$text,
			);
		}

		$map = [];
		foreach ($this->findPseudonyms($conversation) as $pseudonym) {
			$map[$pseudonym->getAnchor()] = $pseudonym->getValue();
		}

		return $map ? strtr($text, $map) : $text;
	}

	/** @param array<string, array{0: string, 1: string}> $spans */
	private function add(array &$spans, ConversationInterface $conversation, PiiFinding $finding, bool $checkReadable): void
	{
		$subject = $finding->subject ?? $this->resolver?->resolve($finding);
		if ($subject === null && $checkReadable && $this->resolver?->isReadable($finding)) {
			return;
		}

		if ($subject !== null) {
			$spans[$finding->text] = [sprintf($this->subjectAnchor, $subject), $this->subjectLabel];
			return;
		}
		$label = $this->labels[$finding->kind] ?? $this->labels[PiiFinding::OTHER];
		$spans[$finding->text] = [$this->anchor($conversation, $label, $finding->text), $label];
	}

	/** The anchor of the value in the conversation - an existing one, or a new [LABEL_n] */
	private function anchor(ConversationInterface $conversation, string $label, string $value): string
	{
		$sameLabel = '~^\[' . preg_quote($label, '~') . '_\d+]$~u';
		$count = 0;
		foreach ($this->findPseudonyms($conversation, withPending: true) as $pseudonym) {
			if ($pseudonym->getValue() === $value) {
				return $pseudonym->getAnchor();
			}
			if (preg_match($sameLabel, $pseudonym->getAnchor())) {
				$count++;
			}
		}

		$anchor = '[' . $label . '_' . ($count + 1) . ']';
		$class = $this->pseudonymClass;
		$this->em->persist((new $class())->setConversation($conversation)->setAnchor($anchor)->setValue($value));

		return $anchor;
	}

	/**
	 * @param bool $withPending incl. the ones not flushed yet (more values in one message)
	 * @return list<PseudonymInterface>
	 */
	private function findPseudonyms(ConversationInterface $conversation, bool $withPending = false): array
	{
		$pseudonyms = $conversation->getId() !== null
			? array_values($this->em->getRepository($this->pseudonymClass)->findBy([$this->conversationField => $conversation]))
			: [];
		if ($withPending) {
			foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
				if ($pending instanceof $this->pseudonymClass && $pending->getConversation() === $conversation) {
					$pseudonyms[] = $pending;
				}
			}
		}

		return $pseudonyms;
	}

	/**
	 * The span as a whole only: a letter at its edge must not continue a letter, a digit
	 * a digit. A finding "Jan" does not rewrite a part of "Janovice", a number "123" a part
	 * of another number; a phone right after letters ("tel777123987") is replaced.
	 */
	private static function wholeSpan(string $span): string
	{
		$guard = static fn(string $char): string => match (true) {
			(bool) preg_match('~\p{L}~u', $char) => '\p{L}',
			(bool) preg_match('~\p{N}~u', $char) => '\p{N}',
			default => '',
		};
		$before = $guard(mb_substr($span, 0, 1));
		$after = $guard(mb_substr($span, -1));

		return ($before !== '' ? '(?<!' . $before . ')' : '')
			. preg_quote($span, '~')
			. ($after !== '' ? '(?!' . $after . ')' : '');
	}
}
