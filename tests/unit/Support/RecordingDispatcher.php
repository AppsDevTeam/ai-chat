<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Dispatch\MessageDispatcher;

class RecordingDispatcher implements MessageDispatcher
{
	/** @var list<array{conversationId: int|string, userMessage: string, attachments: array}> */
	public array $dispatched = [];

	public function dispatch(int|string $conversationId, string $userMessage, array $attachments = []): void
	{
		$this->dispatched[] = compact('conversationId', 'userMessage', 'attachments');
	}
}
