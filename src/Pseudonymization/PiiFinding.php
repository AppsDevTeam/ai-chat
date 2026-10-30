<?php

declare(strict_types=1);

namespace ADT\AiChat\Pseudonymization;

/**
 * One piece of personal data found in the user's text: the exact span as the user
 * wrote it and its kind.
 */
final class PiiFinding
{
	public const NAME = 'name';
	public const BIRTH_NUMBER = 'birth_number';
	public const INSURANCE_NUMBER = 'insurance_number';
	public const FILE_NUMBER = 'file_number';
	public const PHONE = 'phone';
	public const EMAIL = 'email';
	public const ADDRESS = 'address';
	public const BIRTH_DATE = 'birth_date';
	public const BANK_ACCOUNT = 'bank_account';
	public const DOCUMENT_NUMBER = 'document_number';
	public const OTHER = 'other';

	public const KINDS = [
		self::NAME, self::BIRTH_NUMBER, self::INSURANCE_NUMBER, self::FILE_NUMBER, self::PHONE, self::EMAIL,
		self::ADDRESS, self::BIRTH_DATE, self::BANK_ACCOUNT, self::DOCUMENT_NUMBER, self::OTHER,
	];

	/**
	 * @param string $text the exact span of the text
	 * @param string $kind one of {@see KINDS}
	 * @param string $firstName names only: the first name in its base form
	 *        (nominative), '' when unknown
	 * @param string $lastName names only: the last name in its base form
	 * @param int|string|null $subject the data subject the finding belongs to, when
	 *        already known (findings of {@see PiiSubjectResolver::findInText()});
	 *        null = ask {@see PiiSubjectResolver::resolve()}
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $kind,
		public readonly string $firstName = '',
		public readonly string $lastName = '',
		public readonly int|string|null $subject = null,
	) {
	}
}
