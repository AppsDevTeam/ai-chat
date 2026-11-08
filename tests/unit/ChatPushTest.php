<?php

declare(strict_types=1);

use ADT\AiChat\Entity\ConversationInterface;
use ADT\AiChat\Push\ChatPushPublisher;
use ADT\AiChat\Push\MercurePushPublisher;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;

class ChatPushTest extends \Codeception\Test\Unit
{
	public function testTheWorkerPushesTheReplyAndTheWidgetGetsTheSubscription(): void
	{
		$publisher = new class implements ChatPushPublisher {
			/** @var list<int|string|null> */
			public array $changed = [];

			public function conversationChanged(ConversationInterface $conversation): void
			{
				$this->changed[] = $conversation->getId();
			}

			public function subscription(ConversationInterface $conversation): ?array
			{
				return ['url' => '/hub', 'topic' => 'chat/' . $conversation->getId(), 'token' => 't'];
			}
		};
		$env = new ChatTestEnvironment(pushPublisher: $publisher);
		$sent = $env->endpoint->send($env->owner(), null, 'Hi');
		$id = $sent['conversation']['id'];
		$this->assertSame('chat/' . $id, $sent['push']['topic']);

		$env->scriptReply('Hello');
		$env->chat->processQueuedMessage($id, 'Hi');

		$this->assertContains($id, $publisher->changed);
	}

	public function testMercureTokensGrantOneTopicAndAFailedPublishDoesNotThrow(): void
	{
		$publisher = new MercurePushPublisher('http://127.0.0.1:9/hub', '/.well-known/mercure', 'publisher-key-0123456789abcdef0123', 'subscriber-key-0123456789abcdef012', 'app', issuer: 'https://app', audience: 'https://app/.well-known/mercure', timeoutSeconds: 0.2);
		$conversation = new class implements ConversationInterface {
			use \ADT\AiChat\Entity\ConversationTrait;

			public function getId(): int
			{
				return 7;
			}
		};

		$subscription = $publisher->subscription($conversation);
		$this->assertSame('app/conversation/7', $subscription['topic']);
		[$header, $payload, $signature] = explode('.', $subscription['token']);
		$decode = static fn(string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true);
		$this->assertSame('at+jwt', $decode($header)['typ']);
		$claims = $decode($payload);
		$this->assertSame([['match' => 'app/conversation/7']], $claims['authorization_details'][0]['topics']);
		$this->assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
		$this->assertSame('https://app/.well-known/mercure', $claims['aud']);
		$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", 'subscriber-key-0123456789abcdef012', true)), '+/', '-_'), '=');
		$this->assertSame($expected, $signature);

		// Nobody listens on the port - the turn must go on
		$publisher->conversationChanged($conversation);
	}
}
