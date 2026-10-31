<?php

declare(strict_types=1);

use ADT\AiChat\Pseudonymization\OpenAiCompatiblePiiDetector;
use ADT\AiChat\Pseudonymization\PiiCheckUnavailableException;
use ADT\AiChat\Pseudonymization\PiiFinding;
use ADT\AiChat\Tests\Support\ScriptedPiiDetector;

class OpenAiCompatiblePiiDetectorTest extends \Codeception\Test\Unit
{
	public function testReplyIsSanitized(): void
	{
		// Wrapped in ```json, a made-up span, a too short span and an unknown kind
		$detector = new ScriptedPiiDetector("```json\n" . json_encode(['items' => [
			['text' => 'Jan Vymyšlený', 'kind' => 'name', 'first_name' => 'Jan', 'last_name' => 'Vymyšlený'],
			['text' => 'xy', 'kind' => 'other'],
			['text' => ' ABC-1234 ', 'kind' => 'nonsense'],
			['text' => 'žofii', 'kind' => 'name', 'first_name' => ' Žofie ', 'last_name' => ''],
		]]) . "\n```");

		$this->assertEquals(
			[new PiiFinding('ABC-1234', PiiFinding::OTHER), new PiiFinding('žofii', PiiFinding::NAME, 'Žofie')],
			$detector->detect('Zkontroluj ABC-1234 a xy, napiš žofii.'),
		);
	}

	public function testReplyWithoutItemsFindsNothing(): void
	{
		$this->assertSame([], (new ScriptedPiiDetector('{"items": []}'))->detect('Hello'));
		$this->assertSame([], (new ScriptedPiiDetector('{}'))->detect('Hello'));
	}

	public function testNotConfiguredDetector(): void
	{
		$this->assertNull((new OpenAiCompatiblePiiDetector(null, null, required: false))->detect('Hello'));
		$this->assertNull((new OpenAiCompatiblePiiDetector('http://detector.test', '', required: false))->detect('Hello'));

		$this->expectException(PiiCheckUnavailableException::class);
		(new OpenAiCompatiblePiiDetector('', 'model'))->detect('Hello');
	}
}
