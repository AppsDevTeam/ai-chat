<?php

declare(strict_types=1);

use ADT\AiChat\AttachmentPolicy;
use ADT\AiChat\AttachmentUpload;
use ADT\AiChat\ChatOptions;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\ModelSelection;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestConversation;
use ADT\AiChat\Tests\Support\Entity\TestMessage;

class ChatServiceTest extends \Codeception\Test\Unit
{
	private ChatTestEnvironment $env;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
	}

	public function testConversationsAreScopedToTheOwner(): void
	{
		$alice = $this->env->owner();
		$bob = $this->env->owner();

		$conversation = $this->env->chat->createConversation($alice);

		$this->assertNotNull($conversation->getId());
		$this->assertSame($alice, $this->env->chat->getOwner($conversation));
		$this->assertSame($conversation, $this->env->chat->getConversation($conversation->getId(), $alice));
		// A foreign conversation reads as "not found".
		$this->assertNull($this->env->chat->getConversation($conversation->getId(), $bob));
		$this->assertCount(1, $this->env->chat->getConversations($alice));
		$this->assertSame([], $this->env->chat->getConversations($bob));
	}

	public function testDeletedConversationDisappears(): void
	{
		$owner = $this->env->owner();
		$conversation = $this->env->chat->createConversation($owner);

		$this->env->chat->deleteConversation($conversation);

		$this->assertTrue($conversation->isDeleted());
		$this->assertNull($this->env->chat->getConversation($conversation->getId(), $owner));
		$this->assertNull($this->env->chat->findConversation($conversation->getId()));
		$this->assertSame([], $this->env->chat->getConversations($owner));
	}

	public function testEnqueueStoresTheMessageSetsTheTitleAndDispatches(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		$message = $this->env->chat->enqueueMessage($conversation, "  How many\nclients do we have?  ");

		$this->assertSame(MessageInterface::AUTHOR_USER, $message->getAuthor());
		$this->assertSame("How many\nclients do we have?", $message->getContent());
		$this->assertSame('How many clients do we have?', $conversation->getTitle());
		$this->assertSame([[
			'conversationId' => $conversation->getId(),
			'userMessage' => "How many\nclients do we have?",
			'attachments' => [],
		]], $this->env->dispatcher->dispatched);
	}

	public function testEmptyMessageIsRefused(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		try {
			$this->env->chat->enqueueMessage($conversation, '   ');
			$this->fail('An empty message must be refused.');
		} catch (AiChatException $e) {
			$this->assertSame('aiChat.error.emptyMessage', $e->getTranslationKey());
		}

		$this->assertSame([], $this->env->dispatcher->dispatched);
	}

	public function testAttachmentsAreUploadedAndOnlyTheirIdsTravel(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$upload = new AttachmentUpload('report.csv', "a,b\n1,2", 'text/csv', 7, true);

		$message = $this->env->chat->enqueueMessage($conversation, '', [$upload]);

		$expected = [[
			'fileId' => 'file_fake_' . md5('report.csv' . "a,b\n1,2"),
			'mediaType' => 'text/plain',
			'filename' => 'report.csv',
			'size' => 7,
		]];
		$this->assertSame($expected, $message->getAttachments());
		$this->assertSame($expected, $this->env->dispatcher->dispatched[0]['attachments']);
		// Without text the title comes from the first file.
		$this->assertSame('report.csv', $conversation->getTitle());
	}

	public function testAttachmentPolicyIsEnforced(): void
	{
		$env = new ChatTestEnvironment(new ChatOptions(
			TestConversation::class,
			TestMessage::class,
			ownerField: 'owner',
			attachments: new AttachmentPolicy(maxCount: 1, maxBytes: 5),
		));
		$conversation = $env->chat->createConversation($env->owner());

		$cases = [
			'aiChat.error.attachmentTooLarge' => [new AttachmentUpload('big.txt', '123456', 'text/plain', 6, true)],
			'aiChat.error.tooManyAttachments' => [
				new AttachmentUpload('a.txt', 'a', 'text/plain', 1, true),
				new AttachmentUpload('b.txt', 'b', 'text/plain', 1, true),
			],
			'aiChat.error.attachmentType' => [new AttachmentUpload('run.exe', 'MZ', 'application/x-msdownload', 2, true)],
			'aiChat.error.attachmentUploadFailed' => [new AttachmentUpload('broken.txt', '', 'text/plain', 0, false)],
		];

		foreach ($cases as $key => $uploads) {
			try {
				$env->chat->enqueueMessage($conversation, 'see attached', $uploads);
				$this->fail("Expected $key.");
			} catch (AiChatException $e) {
				$this->assertSame($key, $e->getTranslationKey());
			}
		}

		$this->assertSame([], $env->dispatcher->dispatched);
	}

	public function testRateLimitCountsUserMessagesOfTheOwner(): void
	{
		$env = new ChatTestEnvironment(new ChatOptions(
			TestConversation::class,
			TestMessage::class,
			ownerField: 'owner',
			rateLimitPerMinute: 2,
		));
		$owner = $env->owner();
		$first = $env->chat->createConversation($owner);
		$second = $env->chat->createConversation($owner);

		$env->chat->enqueueMessage($first, 'one');
		$env->chat->enqueueMessage($second, 'two');

		try {
			$env->chat->enqueueMessage($first, 'three');
			$this->fail('The rate limit must apply across conversations.');
		} catch (AiChatException $e) {
			$this->assertSame('aiChat.error.rateLimitMinute', $e->getTranslationKey());
			$this->assertSame(['limit' => 2], $e->getTranslationParams());
		}

		// Another owner is not affected.
		$env->chat->enqueueMessage($env->chat->createConversation($env->owner()), 'hello');
		$this->assertCount(3, $env->dispatcher->dispatched);
	}

	public function testProcessQueuedMessageStoresTheReply(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$question = $this->env->chat->enqueueMessage($conversation, 'Hi');
		$this->env->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Hello there']]],
			['id' => 'e2', 'type' => 'span.model_request_end', 'model_usage' => ['input_tokens' => 1000, 'output_tokens' => 7]],
			['id' => 'e3', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];

		$this->env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$replies = $this->env->chat->getMessagesAfter($conversation, $question->getId());
		$this->assertCount(1, $replies);
		$this->assertSame(MessageInterface::AUTHOR_AI, $replies[0]->getAuthor());
		$this->assertSame('Hello there', $replies[0]->getContent());
		$this->assertFalse($replies[0]->isError());
		$this->assertSame(1000, $replies[0]->getTokensInput());
		$this->assertSame(7, $replies[0]->getTokensOutput());
		// The session id was stored on the conversation.
		$this->assertSame('sesn_1', $conversation->getSessionId());

		$this->assertSame(['used' => 1000, 'window' => 200000, 'percent' => 1], $this->env->chat->getContextUsage($conversation));
		$this->assertCount(2, $this->env->chat->getMessages($conversation));
	}

	public function testReplyIsRedacted(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->env->scriptReply('Write to jan.novak@example.com');

		$this->env->chat->processQueuedMessage($conversation->getId(), 'Contact?');

		$reply = $this->env->chat->getMessages($conversation)[0];
		$this->assertStringNotContainsString('jan.novak@example.com', $reply->getContent());
	}

	public function testFailureIsStoredAsATranslatedErrorReply(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->env->client->failAllUserMessages = true;
		$this->env->chat->setTranslator(static fn(string $key, array $params, string $default): string => "[$key]");

		// No exception may escape back to the queue.
		$this->env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$messages = $this->env->chat->getMessages($conversation);
		$this->assertCount(1, $messages);
		$this->assertTrue($messages[0]->isError());
		$this->assertSame(MessageInterface::AUTHOR_AI, $messages[0]->getAuthor());
		// A plain AiChatException keeps its message.
		$this->assertSame('Session refused the message (test).', $messages[0]->getContent());
	}

	public function testUnknownConversationIsIgnoredByTheWorker(): void
	{
		$this->env->chat->processQueuedMessage(999, 'Hi');

		$this->assertSame([], $this->env->client->sentEvents);
	}

	public function testDescribeErrorTranslates(): void
	{
		$this->env->chat->setTranslator(static fn(string $key, array $params, string $default): string => $key . json_encode($params));

		$this->assertSame(
			'aiChat.error.rateLimitHour{"limit":5}',
			$this->env->chat->describeError(AiChatException::translatable('x', 'aiChat.error.rateLimitHour', ['limit' => 5])),
		);
		$this->assertSame('aiChat.error.unexpected[]', $this->env->chat->describeError(new \RuntimeException('secret detail')));
		$this->assertSame('plain', $this->env->chat->describeError(new AiChatException('plain')));
	}

	public function testFindExportMatchesTheTokenOnly(): void
	{
		$message = (new TestMessage())->setToolData([
			['tool_name' => 'execute_sql', 'tool_input' => ['sql' => 'SELECT 2'], 'tool_result' => ['result' => []]],
			[
				'tool_name' => 'export_csv',
				'tool_input' => ['sql' => 'SELECT * FROM client'],
				'tool_result' => ['type' => 'export', 'token' => 'tok123', 'filename' => 'clients.csv', 'title' => 'Clients'],
			],
		]);

		$this->assertSame(
			['sql' => 'SELECT * FROM client', 'filename' => 'clients.csv', 'title' => 'Clients'],
			$this->env->chat->findExport($message, 'tok123'),
		);
		$this->assertNull($this->env->chat->findExport($message, 'wrong'));
		$this->assertNull($this->env->chat->findExport($message, ''));
	}

	public function testModelChoiceIsStoredAndSentAsSessionOverride(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'max');
		$env->scriptReply('Hello');
		$env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$this->assertSame('claude-opus-5-5', $conversation->getModel());
		$this->assertSame('max', $conversation->getEffort());
		$this->assertSame([['id' => 'claude-opus-5-5', 'effort' => 'max']], $env->client->createdSessionModels);
	}

	public function testModelChoiceFallsBackToTheDefaults(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi');

		$this->assertSame('claude-sonnet-5-5', $conversation->getModel());
		$this->assertSame('high', $conversation->getEffort());
	}

	public function testModelChoiceIsLockedAfterTheFirstMessage(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());
		$this->assertFalse($env->chat->isModelLocked($conversation));

		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'medium');
		$env->chat->enqueueMessage($conversation, 'Again', [], 'claude-sonnet-5-5', 'max');

		$this->assertTrue($env->chat->isModelLocked($conversation));
		$this->assertSame('claude-opus-5-5', $conversation->getModel());
		$this->assertSame('medium', $conversation->getEffort());
	}

	public function testModelOrEffortNotOfferedIsRefused(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		foreach ([['claude-haiku-4-5', 'high', 'aiChat.error.unknownModel'], ['claude-opus-5-5', 'xhigh', 'aiChat.error.unknownEffort']] as [$model, $effort, $key]) {
			try {
				$env->chat->enqueueMessage($conversation, 'Hi', [], $model, $effort);
				$this->fail('A model or effort that is not offered must be refused.');
			} catch (AiChatException $e) {
				$this->assertSame($key, $e->getTranslationKey());
			}
		}

		$this->assertNull($conversation->getModel());
		$this->assertSame([], $env->dispatcher->dispatched);
	}

	public function testWithoutModelSelectionTheSessionRunsOnTheAgentModel(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		$this->env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'max');
		$this->env->scriptReply('Hello');
		$this->env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$this->assertNull($conversation->getModel());
		$this->assertSame([null], $this->env->client->createdSessionModels);
	}

	public function testContextUsageUsesTheWindowOfTheConversationModel(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());
		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5');
		$env->client->frames = [[
			['id' => 'e1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Hello']]],
			['id' => 'e2', 'type' => 'span.model_request_end', 'model_usage' => ['input_tokens' => 100000, 'output_tokens' => 7]],
			['id' => 'e3', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
		]];
		$env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$this->assertSame(['used' => 100000, 'window' => 1000000, 'percent' => 10], $env->chat->getContextUsage($conversation));
	}

	public function testRatingIsStoredAndCleared(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		$this->env->chat->rateConversation($conversation, ConversationInterface::RATING_DOWN, '  Wrong numbers  ');

		$this->assertSame(-1, $conversation->getRating());
		$this->assertSame('Wrong numbers', $conversation->getRatingComment());
		$this->assertNotNull($conversation->getRated());

		$this->env->chat->rateConversation($conversation, ConversationInterface::RATING_UP, '');
		$this->assertSame(1, $conversation->getRating());
		$this->assertNull($conversation->getRatingComment());

		$this->env->chat->rateConversation($conversation, null, 'ignored');
		$this->assertNull($conversation->getRating());
		$this->assertNull($conversation->getRatingComment());
		$this->assertNull($conversation->getRated());
	}

	public function testInvalidRatingIsRefused(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		try {
			$this->env->chat->rateConversation($conversation, 5);
			$this->fail('A rating other than 1 / -1 must be refused.');
		} catch (AiChatException $e) {
			$this->assertSame('aiChat.error.invalidRating', $e->getTranslationKey());
		}

		$this->assertNull($conversation->getRating());
	}

	private function withModelSelection(): ChatTestEnvironment
	{
		return new ChatTestEnvironment(new ChatOptions(
			TestConversation::class,
			TestMessage::class,
			ownerField: 'owner',
			modelSelection: new ModelSelection([
				'claude-sonnet-5-5' => ['label' => 'Sonnet 5.5', 'contextWindowTokens' => 1000000],
				'claude-opus-5-5' => ['label' => 'Opus 5.5', 'contextWindowTokens' => 1000000],
			]),
		));
	}
}
