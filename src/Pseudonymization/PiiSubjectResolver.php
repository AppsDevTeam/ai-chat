<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/**
 * The project's knowledge of its data subjects (patients, clients ...): who a
 * finding belongs to and what stays readable. The pseudonymizer works without it -
 * every finding then gets a generic anchor.
 */
interface PiiSubjectResolver
{
	/**
	 * The one subject the finding belongs to (looked up by the birth number, e-mail,
	 * phone, the name in {@see PiiFinding::$firstName}/{@see PiiFinding::$lastName}
	 * ...), null when there is none or more than one. The subject's anchor carries
	 * the id, so the AI can use it in its tools right away.
	 */
	public function resolve(PiiFinding $finding): int|string|null;

	/**
	 * A finding that stays in the text as it is - e.g. the name of an employee that
	 * matches no subject. Asked only for findings without a subject.
	 */
	public function isReadable(PiiFinding $finding): bool;

	/**
	 * Additional findings from the project's own data, e.g. names matched against
	 * the subjects in the database. Only spans to replace - set
	 * {@see PiiFinding::$subject} when the subject is known.
	 *
	 * @return list<PiiFinding>
	 */
	public function findInText(string $text): array;

	/** What the user sees instead of the subject's anchor (the full name); null keeps the anchor. */
	public function label(int|string $subject): ?string;
}
