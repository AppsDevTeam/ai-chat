<?php

declare(strict_types=1);

use ADT\AiChat\PiiResponseFilter;

class PiiResponseFilterTest extends \Codeception\Test\Unit
{
	public function testRedactsEmailsPhonesAccountsAndTokens(): void
	{
		$filter = new PiiResponseFilter();

		$this->assertStringNotContainsString('jan@example.com', $filter->filter('Mail: jan@example.com'));
		$this->assertStringNotContainsString('+420 601 234 567', $filter->filter('Call +420 601 234 567'));
		$this->assertStringNotContainsString('123456-1234567890/0800', $filter->filter('Acct 123456-1234567890/0800'));
		$this->assertStringNotContainsString(str_repeat('a1', 20), $filter->filter('token ' . str_repeat('a1', 20)));
	}

	public function testLeavesOrdinaryNumbersAndDatesAlone(): void
	{
		$filter = new PiiResponseFilter();

		$this->assertSame('Revenue 06/2024 was 1250 CZK', $filter->filter('Revenue 06/2024 was 1250 CZK'));
	}

	public function testValueRedactionIsCaseInsensitiveAndBounded(): void
	{
		$filter = new PiiResponseFilter();
		$out = $filter->filter('Client NOVAK Jan and Novakova', ['Novak']);

		$this->assertStringNotContainsString('NOVAK ', $out);
		// A value must not be redacted inside a longer word.
		$this->assertStringContainsString('Novakova', $out);
	}

	public function testShortValuesAreNotRedacted(): void
	{
		$filter = new PiiResponseFilter();

		$this->assertSame('a ok b', $filter->filter('a ok b', ['ok']));
	}

	public function testCustomLabelsAndPhonePrefix(): void
	{
		$filter = new PiiResponseFilter(['email' => '[e-mail odstraněn]'], '49');

		$this->assertStringContainsString('[e-mail odstraněn]', $filter->filter('jan@example.com'));
		// German prefix now matches...
		$this->assertStringNotContainsString('+49 151 123 456', $filter->filter('+49 151 123 456'));
		// ...and the Czech one no longer does.
		$this->assertStringContainsString('+420 601 234 567', $filter->filter('+420 601 234 567'));
	}
}
