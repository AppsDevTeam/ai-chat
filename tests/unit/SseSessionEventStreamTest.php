<?php

declare(strict_types=1);

use ADT\AiChat\Client\SseSessionEventStream;

class SseSessionEventStreamTest extends \Codeception\Test\Unit
{
	/** @return resource */
	private static function body(string $text)
	{
		$handle = fopen('php://temp', 'r+b');
		fwrite($handle, $text);
		rewind($handle);

		return $handle;
	}

	public function testEventsAreDecodedFromDataLines(): void
	{
		$stream = new SseSessionEventStream(self::body(
			": keep-alive\n\n"
			. "event: agent.message\r\n"
			. "data: {\"type\":\"agent.message\",\"id\":\"sevt_1\",\n"
			. "data: \"content\":[]}\r\n\r\n"
			. "event: session.status_idle\n"
			. "data: {\"type\":\"session.status_idle\",\"id\":\"sevt_2\"}\n\n"
			. "data: not json\n\n",
		));

		$events = $stream->read(1.0);

		$this->assertSame(['sevt_1', 'sevt_2'], array_column($events, 'id'));
		$this->assertSame('agent.message', $events[0]['type']);
	}

	public function testAClosedStreamThrowsOnTheNextRead(): void
	{
		$stream = new SseSessionEventStream(self::body("data: {\"type\":\"x\",\"id\":\"1\"}\n\n"));
		$this->assertCount(1, $stream->read(1.0));

		$this->expectException(\RuntimeException::class);
		$stream->read(1.0);
	}
}
