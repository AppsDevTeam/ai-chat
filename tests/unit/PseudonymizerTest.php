<?php

declare(strict_types=1);

use ADT\AiChat\Pseudonymization\OpenAiCompatiblePiiDetector;
use ADT\AiChat\Pseudonymization\PiiCheckUnavailableException;
use ADT\AiChat\Pseudonymization\PiiDetector;
use ADT\AiChat\Pseudonymization\PiiFinding;
use ADT\AiChat\Pseudonymization\Pseudonymizer;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestConversation;
use ADT\AiChat\Tests\Support\Entity\TestPseudonym;
use ADT\AiChat\Tests\Support\FakeSubjectResolver;
use ADT\AiChat\Tests\Support\ScriptedPiiDetector;

class PseudonymizerTest extends \Codeception\Test\Unit
{
	private const SUBJECT = 7;

	private const LABELS = [
		'name' => 'OSOBA', 'birth_number' => 'RČ', 'insurance_number' => 'POJIŠTĚNEC', 'file_number' => 'KARTA',
		'phone' => 'TELEFON', 'email' => 'EMAIL', 'address' => 'ADRESA', 'birth_date' => 'NAROZENÍ',
		'bank_account' => 'ÚČET', 'document_number' => 'DOKLAD', 'other' => 'ÚDAJ',
	];

	private ChatTestEnvironment $env;

	private TestConversation $conversation;

	private FakeSubjectResolver $resolver;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
		/** @var TestConversation $conversation */
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->conversation = $conversation;
		$this->resolver = new FakeSubjectResolver(
			subjects: [
				'885512/3457' => self::SUBJECT,
				'zofie@example.test' => self::SUBJECT,
				'777 123 987' => self::SUBJECT,
				'PS-77001' => self::SUBJECT,
				'Žofie Zkušebníková' => self::SUBJECT,
			],
			readable: ['Jan Novák'],
			labels: [self::SUBJECT => 'Žofie Zkušebníková'],
		);
	}

	public function testDataOfOneSubjectGetsTheSubjectAnchor(): void
	{
		$result = $this->pseudonymizer()->pseudonymize(
			$this->conversation,
			'RČ 885512/3457, tel. 777 123 987, napiš na zofie@example.test',
		);

		$this->assertSame('RČ [PACIENT #7], tel. [PACIENT #7], napiš na [PACIENT #7]', $result->replaced);
		$this->assertSame(['PACIENT' => 3], $result->findings);
		$this->assertSame([], $this->pseudonyms(), 'The subject anchor carries the id, nothing to store');
	}

	public function testUnknownDataGetsStoredGenericAnchors(): void
	{
		$pseudonymizer = $this->pseudonymizer();

		$first = $pseudonymizer->pseudonymize($this->conversation, 'RČ 0101011234 a 0202021234, e-mail x@example.test');
		$second = $pseudonymizer->pseudonymize($this->conversation, 'Ještě 0202021234 a 0303031234.');

		$this->assertSame('RČ [RČ_1] a [RČ_2], e-mail [EMAIL_1]', $first->replaced);
		$this->assertSame(['EMAIL' => 1, 'RČ' => 2], $first->findings);
		$this->assertSame('Ještě [RČ_2] a [RČ_3].', $second->replaced, 'The same value keeps its anchor in the conversation');
		$this->assertSame(
			['[RČ_1]' => '0101011234', '[RČ_2]' => '0202021234', '[EMAIL_1]' => 'x@example.test', '[RČ_3]' => '0303031234'],
			$this->pseudonyms(),
		);
	}

	public function testTheNoteIsAddedOnlyForTheAi(): void
	{
		$result = $this->pseudonymizer()->pseudonymize($this->conversation, 'RČ 0101011234');

		$this->assertSame('RČ [RČ_1]', $result->replaced);
		$this->assertStringStartsWith("RČ [RČ_1]\n\n[Poznámka: [PACIENT #123]", $result->forAi);
	}

	public function testTextWithoutPersonalDataIsUntouched(): void
	{
		$text = 'How many orders of Dental Hygiene did we have in 09/2026?';

		$result = $this->pseudonymizer()->pseudonymize($this->conversation, $text);

		$this->assertSame($text, $result->forAi);
		$this->assertNull($result->replaced);
		$this->assertSame([], $result->findings);
		$this->assertFalse($result->detectorChecked);
	}

	public function testBankAccountsAreFoundWithoutTheDetector(): void
	{
		$result = $this->pseudonymizer()->pseudonymize(
			$this->conversation,
			'Vrať zálohu za 10/2026 na 19-2000145399/0800 nebo CZ65 0800 0000 1920 0014 5399, RČ 885512/3457.',
		);

		$this->assertSame('Vrať zálohu za 10/2026 na [ÚČET_1] nebo [ÚČET_2], RČ [PACIENT #7].', $result->replaced);
		$this->assertSame(['PACIENT' => 1, 'ÚČET' => 2], $result->findings);
	}

	public function testDetectorFindingsGetAnchorsAndRestore(): void
	{
		$pseudonymizer = $this->pseudonymizer(ScriptedPiiDetector::returning([
			['text' => 'žofii zkušebníkové', 'kind' => 'name', 'first_name' => 'Žofie', 'last_name' => 'Zkušebníková'],
			['text' => 'Lidická 12', 'kind' => 'address'],
			['text' => '12. 3. 1985', 'kind' => 'birth_date'],
			['text' => '205AB1234', 'kind' => 'document_number'],
			['text' => 'PS-77001', 'kind' => 'file_number'],
		]));

		$result = $pseudonymizer->pseudonymize($this->conversation, 'pošli žofii zkušebníkové výpis, bydlí Lidická 12, narozena 12. 3. 1985, OP 205AB1234, karta PS-77001');

		$this->assertTrue($result->detectorChecked);
		$this->assertSame('pošli [PACIENT #7] výpis, bydlí [ADRESA_1], narozena [NAROZENÍ_1], OP [DOKLAD_1], karta [PACIENT #7]', $result->replaced);
		$this->assertSame(['ADRESA' => 1, 'DOKLAD' => 1, 'NAROZENÍ' => 1, 'PACIENT' => 2], $result->findings);

		$this->assertSame(
			'Žofie Zkušebníková: Lidická 12, 12. 3. 1985, 205AB1234; [PACIENT #8] beze jména.',
			$pseudonymizer->restore($this->conversation, '[PACIENT #7]: [ADRESA_1], [NAROZENÍ_1], [DOKLAD_1]; [PACIENT #8] beze jména.'),
		);
	}

	public function testReadableNameStaysUnlessItBelongsToASubject(): void
	{
		$pseudonymizer = $this->pseudonymizer(ScriptedPiiDetector::returning([
			['text' => 'Jan Novák', 'kind' => 'name', 'first_name' => 'Jan', 'last_name' => 'Novák'],
			['text' => 'Petr Neznámý', 'kind' => 'name', 'first_name' => 'Petr', 'last_name' => 'Neznámý'],
		]));

		$this->assertSame('Objednej [OSOBA_1] k doktorovi Jan Novák.', $pseudonymizer->pseudonymize($this->conversation, 'Objednej Petr Neznámý k doktorovi Jan Novák.')->replaced);

		$this->resolver->subjects['Jan Novák'] = 9;
		$this->assertSame('Objednej [OSOBA_1] k doktorovi [PACIENT #9].', $pseudonymizer->pseudonymize($this->conversation, 'Objednej Petr Neznámý k doktorovi Jan Novák.')->replaced, 'The subject wins');
	}

	public function testResolverFindingsAreUsedAsTheyAre(): void
	{
		$this->resolver->readable[] = 'Novákové';
		$this->resolver->inText = [
			new PiiFinding('Žofii Zkušebníkové', PiiFinding::NAME, subject: self::SUBJECT),
			new PiiFinding('paní Novákové', PiiFinding::NAME),
		];

		$result = $this->pseudonymizer()->pseudonymize($this->conversation, 'Pošli SMS Žofii Zkušebníkové a paní Novákové.');

		$this->assertSame('Pošli SMS [PACIENT #7] a [OSOBA_1].', $result->replaced);
	}

	public function testOnlyWholeSpansAreReplaced(): void
	{
		$pseudonymizer = $this->pseudonymizer(ScriptedPiiDetector::returning([
			['text' => 'Jan', 'kind' => 'name', 'first_name' => 'Jan', 'last_name' => ''],
		]));

		$this->assertSame('[OSOBA_1] bydlí v Janovicích.', $pseudonymizer->pseudonymize($this->conversation, 'Jan bydlí v Janovicích.')->replaced);
		$this->assertSame('Volej na tel[TELEFON_1].', $this->pseudonymizer()->pseudonymize($this->conversation, 'Volej na tel777 000 111.')->replaced, 'A phone right after letters');
	}

	public function testRequiredCheckStopsTheTextBeforeAnyAnchorExists(): void
	{
		$pseudonymizer = $this->pseudonymizer(new OpenAiCompatiblePiiDetector(null, null, required: true));

		try {
			$pseudonymizer->pseudonymize($this->conversation, 'Paní Neexistující má RČ 0101011234.');
			$this->fail('The required check must stop the text.');
		} catch (PiiCheckUnavailableException $e) {
			$this->assertSame(PiiCheckUnavailableException::TRANSLATION_KEY, $e->getTranslationKey());
			$this->assertStringContainsString('not configured', $e->getReason());
		}

		$this->env->em->flush();
		$this->assertSame([], $this->pseudonyms());
	}

	public function testFailedDetectorStopsTheTextOnlyWhenRequired(): void
	{
		$text = 'Paní Neexistující má RČ 0101011234.';
		foreach (['outage' => new \RuntimeException('Connection refused'), 'no JSON' => 'I do not know.'] as $case => $response) {
			try {
				$this->pseudonymizer(new ScriptedPiiDetector($response, required: true))->pseudonymize($this->conversation, $text);
				$this->fail("$case: the required check must stop the text.");
			} catch (PiiCheckUnavailableException $e) {
				$this->assertStringNotContainsString('I do not know', $e->getReason(), 'The reply of the model never goes to the log');
			}

			$result = $this->pseudonymizer(new ScriptedPiiDetector($response))->pseudonymize($this->conversation, $text);
			$this->assertFalse($result->detectorChecked, $case);
			$this->assertStringNotContainsString('0101011234', $result->forAi, "$case: the patterns still apply");
		}
	}

	public function testDefaultsAreEnglishAndWorkWithoutAResolver(): void
	{
		$pseudonymizer = new Pseudonymizer($this->env->em, TestPseudonym::class);

		$result = $pseudonymizer->pseudonymize($this->conversation, 'Mail jane@example.test, born 885512/3457.');

		$this->assertSame('Mail [EMAIL_1], born [BIRTH_NUMBER_1].', $result->replaced);
		$this->assertStringContainsString('[SUBJECT #123] is the data subject', $result->forAi);
		$this->assertSame(
			'[SUBJECT #7] jane@example.test',
			$pseudonymizer->restore($this->conversation, '[SUBJECT #7] [EMAIL_1]'),
		);
	}

	public function testSubjectAnchorMustContainTheId(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new Pseudonymizer($this->env->em, TestPseudonym::class, subjectAnchor: '[PATIENT]');
	}

	private function pseudonymizer(?PiiDetector $detector = null): Pseudonymizer
	{
		return new Pseudonymizer(
			$this->env->em,
			TestPseudonym::class,
			$detector,
			$this->resolver,
			'[PACIENT #%s]',
			self::LABELS,
			'[Poznámka: %1$s je pacient.]',
		);
	}

	/** @return array<string, string> anchor => value */
	private function pseudonyms(): array
	{
		$map = [];
		foreach ($this->env->em->getRepository(TestPseudonym::class)->findBy([], ['id' => 'ASC']) as $pseudonym) {
			$map[$pseudonym->getAnchor()] = $pseudonym->getValue();
		}

		return $map;
	}
}
