<?php

declare(strict_types=1);

use ADT\AiChat\AttachmentPolicy;
use ADT\AiChat\AttachmentUpload;
use ADT\AiChat\ChatOptions;
use ADT\AiChat\Entity\MessageInterface;
use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Exception\AiChatException;
use ADT\AiChat\Pseudonymization\OpenAiCompatiblePiiDetector;
use ADT\AiChat\Pseudonymization\PiiCheckUnavailableException;
use ADT\AiChat\Pseudonymization\PiiDetector;
use ADT\AiChat\Pseudonymization\Pseudonymizer;
use ADT\AiChat\Pseudonymization\PseudonymizedText;
use ADT\AiChat\ModelSelection;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestConversation;
use ADT\AiChat\Tests\Support\Entity\TestMessage;
use ADT\AiChat\Tests\Support\Entity\TestPseudonym;
use ADT\AiChat\Tests\Support\FakeSubjectResolver;
use Doctrine\ORM\EntityManager;

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
			pendingTurnSeconds: 0,
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

	public function testPseudonymizedTurn(): void
	{
		$env = $this->pseudonymizingEnvironment();
		$conversation = $env->chat->createConversation($env->owner());
		$env->scriptReply('Called [PHONE_1] about [SUBJECT #5], mail [EMAIL_1].');

		$env->chat->processQueuedMessage($conversation->getId(), 'Call 777 111 222 about jane@example.test, mail x.y@example.test');

		$sent = json_encode($env->client->sentEvents, JSON_UNESCAPED_UNICODE);
		$this->assertStringContainsString('Call [PHONE_1] about [SUBJECT #5], mail [EMAIL_1]', $sent);
		$this->assertStringNotContainsString('777 111 222', $sent);
		$this->assertStringNotContainsString('example.test', $sent);

		$reply = $env->chat->getMessages($conversation)[0];
		// Restored after the output filter - it would redact the real e-mail otherwise
		$this->assertSame('Called 777 111 222 about Jane Doe, mail x.y@example.test.', $reply->getContent());
		$this->assertSame('Call [PHONE_1] about [SUBJECT #5], mail [EMAIL_1]', $reply->getSentContent());

		[$event, $conversationId, $result] = $env->piiCheckListener->events[0];
		$this->assertSame('checked', $event);
		$this->assertSame($conversation->getId(), $conversationId);
		$this->assertInstanceOf(PseudonymizedText::class, $result);
		$this->assertSame(['EMAIL' => 1, 'PHONE' => 1, 'SUBJECT' => 1], $result->findings);
	}

	public function testNothingReplacedLeavesNoSentContent(): void
	{
		$env = $this->pseudonymizingEnvironment();
		$conversation = $env->chat->createConversation($env->owner());
		$env->scriptReply('42');

		$env->chat->processQueuedMessage($conversation->getId(), 'How many clients?');

		$this->assertNull($env->chat->getMessages($conversation)[0]->getSentContent());
		$this->assertStringContainsString('How many clients?', json_encode($env->client->sentEvents));
	}

	public function testUnavailableRequiredCheckRepliesWithAnErrorInsteadOfTheTurn(): void
	{
		$env = $this->pseudonymizingEnvironment(new OpenAiCompatiblePiiDetector(null, null, required: true));
		$env->chat->setTranslator(static fn(string $key, array $params, string $default): string => "[$key] $default");
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->processQueuedMessage($conversation->getId(), 'Call 777 111 222');

		$this->assertSame([], $env->client->sentEvents, 'Nothing goes to the AI');
		$messages = $env->chat->getMessages($conversation);
		$this->assertCount(1, $messages);
		$this->assertTrue($messages[0]->isError());
		$this->assertSame('[aiChat.error.piiCheckUnavailable] ' . PiiCheckUnavailableException::USER_MESSAGE, $messages[0]->getContent());
		$this->assertNull($messages[0]->getSentContent());
		$this->assertSame([], $env->em->getRepository(TestPseudonym::class)->findAll(), 'No anchors');
		$this->assertSame('unavailable', $env->piiCheckListener->events[0][0]);
	}

	public function testTheTranscriptForANewSessionIsPseudonymizedToo(): void
	{
		$env = $this->pseudonymizingEnvironment();
		$conversation = $env->chat->createConversation($env->owner());
		$env->scriptReply('Calling [PHONE_1].');
		$env->chat->enqueueMessage($conversation, 'Call 777 111 222');
		$env->chat->processQueuedMessage($conversation->getId(), 'Call 777 111 222');

		// An expired session - the next turn sends the transcript to a new one
		$conversation->setSessionId(null);
		$env->scriptReply('Done');
		$env->chat->enqueueMessage($conversation, 'Thanks');
		$env->chat->processQueuedMessage($conversation->getId(), 'Thanks');

		$history = end($env->client->sentEvents)[0]['content'][0]['text'];
		$this->assertStringContainsString("User: Call [PHONE_1]\n\nAssistant: Calling [PHONE_1].", $history);
		$this->assertStringNotContainsString('777 111 222', json_encode($env->client->sentEvents));
	}

	private function pseudonymizingEnvironment(?PiiDetector $detector = null): ChatTestEnvironment
	{
		$resolver = new FakeSubjectResolver(subjects: ['jane@example.test' => 5], labels: [5 => 'Jane Doe']);

		return new ChatTestEnvironment(
			pseudonymizer: static fn(EntityManager $em): Pseudonymizer => new Pseudonymizer($em, TestPseudonym::class, $detector, $resolver),
		);
	}

	public function testModelChoiceIsStoredAndSentAsSessionOverride(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'max');
		$env->scriptReply('Hello');
		$env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$this->assertSame('claude-opus-5-5', $env->chat->getModelChoice($conversation)['model']);
		$this->assertSame('max', $env->chat->getModelChoice($conversation)['effort']);
		$this->assertSame([['id' => 'claude-opus-5-5', 'effort' => 'max']], $env->client->createdSessionModels);
	}

	public function testModelChoiceFallsBackToTheDefaults(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi');

		$this->assertSame('claude-sonnet-5-5', $env->chat->getModelChoice($conversation)['model']);
		$this->assertSame('high', $env->chat->getModelChoice($conversation)['effort']);
	}

	public function testTheWidgetGetsTheContextWindowOfEachModel(): void
	{
		$selection = new ModelSelection([
			'claude-opus-5-5' => ['label' => 'Opus 5.5', 'contextWindowTokens' => 1000000],
			'claude-haiku' => ['label' => 'Haiku'],
		]);

		$this->assertSame([
			['id' => 'claude-opus-5-5', 'label' => 'Opus 5.5', 'contextWindowTokens' => 1000000],
			['id' => 'claude-haiku', 'label' => 'Haiku', 'contextWindowTokens' => null],
		], $selection->toClient()['models']);
	}

	public function testModelCanBeSwitchedWithinAConversation(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'medium');
		$env->scriptReply('Hello');
		$env->chat->processQueuedMessage($conversation->getId(), 'Hi');
		$this->assertSame('sesn_1', $conversation->getSessionId());

		$env->chat->enqueueMessage($conversation, 'Again', [], 'claude-sonnet-5-5', 'max');

		// The session runs on the old model - the next turn starts a new one.
		$this->assertSame('claude-sonnet-5-5', $env->chat->getModelChoice($conversation)['model']);
		$this->assertSame('max', $env->chat->getModelChoice($conversation)['effort']);
		$this->assertNull($conversation->getSessionId());

		$env->scriptReply('Hello again');
		$env->chat->processQueuedMessage($conversation->getId(), 'Again');

		$this->assertSame([['id' => 'claude-opus-5-5', 'effort' => 'medium'], ['id' => 'claude-sonnet-5-5', 'effort' => 'max']], $env->client->createdSessionModels);

		// The new session got the earlier transcript ahead of the message.
		$content = end($env->client->sentEvents)[0]['content'];
		$this->assertCount(2, $content);
		$this->assertStringContainsString("<conversation_history>\nUser: Hi\n\nAssistant: Hello\n</conversation_history>", $content[0]['text']);
		$this->assertSame(['type' => 'text', 'text' => 'Again'], $content[1]);

		$this->assertSame(
			[['user', 'claude-opus-5-5', 'medium'], ['ai', 'claude-opus-5-5', 'medium'], ['user', 'claude-sonnet-5-5', 'max'], ['ai', 'claude-sonnet-5-5', 'max']],
			array_map(static fn($m): array => [$m->getAuthor(), $m->getModel(), $m->getEffort()], $env->chat->getMessages($conversation)),
		);
	}

	public function testUnchangedModelKeepsTheSession(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'medium');
		$env->scriptReply('Hello');
		$env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		// The same choice, and no choice at all, keep the model and the session.
		$env->chat->enqueueMessage($conversation, 'Again', [], 'claude-opus-5-5', 'medium');
		$env->scriptReply('Hello again');
		$env->chat->processQueuedMessage($conversation->getId(), 'Again');
		$env->chat->enqueueMessage($conversation, 'Once more');

		$this->assertSame('claude-opus-5-5', $env->chat->getModelChoice($conversation)['model']);
		$this->assertSame('medium', $env->chat->getModelChoice($conversation)['effort']);
		$this->assertSame('sesn_1', $conversation->getSessionId());
		$this->assertCount(1, $env->client->createdSessionModels);
	}

	public function testHistorySummarizesToolCallsAndLeavesOutOldMessages(): void
	{
		$env = $this->withModelSelection();
		$conversation = $env->chat->createConversation($env->owner());

		$env->chat->enqueueMessage($conversation, 'Revenue?');
		$env->client->frames = [
			[
				['id' => 'evt_tool', 'type' => 'agent.custom_tool_use', 'name' => 'render_table', 'input' => ['title' => 'Revenue', 'headers' => ['Branch', 'CZK'], 'rows' => '[["Praha", 100]]']],
				['id' => 'evt_req', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'requires_action']],
			],
			[
				['id' => 'evt_msg', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'Here it is']]],
				['id' => 'evt_idle', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
			],
		];
		$env->chat->processQueuedMessage($conversation->getId(), 'Revenue?');
		$env->chat->enqueueMessage($conversation, 'And now?');

		$history = $env->chat->buildHistory($conversation);
		$this->assertStringContainsString("User: Revenue?\n\n[render_table] Revenue\nBranch | CZK\nPraha | 100\nAssistant: Here it is\n</conversation_history>", $history);
		$this->assertStringNotContainsString('And now?', $history);
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

		$this->assertNull($env->chat->getModelChoice($conversation));
		$this->assertSame([], $env->dispatcher->dispatched);
	}

	public function testWithoutModelSelectionTheSessionRunsOnTheAgentModel(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());

		$this->env->chat->enqueueMessage($conversation, 'Hi', [], 'claude-opus-5-5', 'max');
		$this->env->scriptReply('Hello');
		$this->env->chat->processQueuedMessage($conversation->getId(), 'Hi');

		$this->assertSame(['model' => null, 'effort' => null], $this->env->chat->getModelChoice($conversation));
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

		$this->assertSame([
			'used' => 100000,
			'window' => 1000000,
			'percent' => 10,
			'model' => 'claude-opus-5-5',
			'effort' => 'high',
			// a new session (another model picked in the widget): first input + the transcript
			'historyTokens' => 100000 + (int) ceil(mb_strlen($env->chat->buildHistory($conversation)) / 3),
		], $env->chat->getContextUsage($conversation));
		$this->assertStringContainsString('Assistant: Hello', $env->chat->buildHistory($conversation));
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

	public function testAMessageWaitingForItsReplyBlocksTheNextOne(): void
	{
		$env = new ChatTestEnvironment();
		$conversation = $env->chat->createConversation($env->owner());
		$env->chat->enqueueMessage($conversation, 'one');

		try {
			$env->chat->enqueueMessage($conversation, 'two');
			$this->fail('A second turn must not start in the same session.');
		} catch (AiChatException $e) {
			$this->assertSame('aiChat.error.turnPending', $e->getTranslationKey());
		}

		$env->scriptReply('Done');
		$env->chat->processQueuedMessage($conversation->getId(), 'one');
		$env->chat->enqueueMessage($conversation, 'two');
		$this->assertCount(2, $env->dispatcher->dispatched);
	}

	public function testAnotherUserWritesIntoTheConversation(): void
	{
		$env = new ChatTestEnvironment(new ChatOptions(
			TestConversation::class,
			TestMessage::class,
			ownerField: 'owner',
			rateLimitPerMinute: 1,
			senderField: 'sender',
			pendingTurnSeconds: 0,
		));
		$alice = $env->owner();
		$bob = $env->owner();
		$conversation = $env->chat->createConversation($alice);

		$own = $env->chat->enqueueMessage($conversation, 'mine');
		$foreign = $env->chat->enqueueMessage($conversation, 'continuing', sender: $bob);

		$this->assertSame($alice, $env->chat->getSender($own));
		$this->assertSame($bob, $env->chat->getSender($foreign));
		$this->assertTrue($env->chat->isSameUser($bob, $bob->id));
		$this->assertFalse($env->chat->isSameUser($alice, $bob));

		// The rate limit counts per sender - Alice is at her limit, Bob's message did not count for her.
		try {
			$env->chat->enqueueMessage($conversation, 'again');
			$this->fail('Alice is over her rate limit.');
		} catch (AiChatException $e) {
			$this->assertSame('aiChat.error.rateLimitMinute', $e->getTranslationKey());
		}
	}

	public function testHeartbeatOfTheWorkerKeepsTheTurnPending(): void
	{
		$env = new ChatTestEnvironment(new ChatOptions(TestConversation::class, TestMessage::class, ownerField: 'owner', pendingTurnSeconds: 600));
		$conversation = $env->chat->createConversation($env->owner());
		$message = $env->chat->enqueueMessage($conversation, 'Hi');

		// The message is older than the limit - the turn would count as lost...
		$message->setCreated(new \DateTimeImmutable('-20 minutes'));
		$env->em->flush();
		$this->assertFalse($env->chat->cancelTurn($conversation));

		// ...unless the worker shows signs of life.
		$conversation->setTurnActivity(new \DateTimeImmutable('-1 minute'));
		$env->em->flush();
		$this->assertNotNull($env->chat->getTurnState($conversation));
		$this->assertTrue($env->chat->cancelTurn($conversation));
	}

	public function testInterruptedReplyGoesToTheHistory(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->env->chat->enqueueMessage($conversation, 'Hi');
		$this->env->chat->cancelTurn($conversation);
		$this->env->chat->processQueuedMessage($conversation->getId(), 'Hi');
		$this->env->chat->enqueueMessage($conversation, 'Again');

		$this->assertStringContainsString("User: Hi\n\n[the user stopped this reply]", $this->env->chat->buildHistory($conversation));
	}
}
