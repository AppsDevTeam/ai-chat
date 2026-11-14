<?php

declare(strict_types=1);

use ADT\AiChat\Client\ApiKeyProvider;
use ADT\AiChat\Client\GuzzleManagedAgentsClient;
use ADT\AiChat\Exception\ApiUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class GuzzleManagedAgentsClientTest extends \Codeception\Test\Unit
{
	private MockHandler $mock;

	/** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
	private array $history = [];

	private function client(string|ApiKeyProvider $apiKey = 'sk-test'): GuzzleManagedAgentsClient
	{
		$this->mock = new MockHandler();
		$this->history = [];
		$stack = HandlerStack::create($this->mock);
		$stack->push(Middleware::history($this->history));

		return new class ($stack, $apiKey) extends GuzzleManagedAgentsClient {
			public function __construct(private readonly HandlerStack $stack, string|ApiKeyProvider $apiKey)
			{
				parent::__construct($apiKey, 'claude-test', 'https://api.test/v1/');
			}

			protected function createHttpClient(): ClientInterface
			{
				return new Client(['handler' => $this->stack]);
			}
		};
	}

	public function testFixedKeyIsSentWithEveryRequest(): void
	{
		$client = $this->client();
		$this->mock->append(new Response(200, [], '{"id":"env_1"}'));

		$client->createEnvironment('env');

		$this->assertSame('sk-test', $this->history[0]['request']->getHeaderLine('x-api-key'));
	}

	/** A queue consumer keeps one client for hours - a changed key must apply at once. */
	public function testProviderIsAskedOnEveryRequest(): void
	{
		$provider = new class implements ApiKeyProvider {
			public string $key = 'sk-first';

			public function getApiKey(): string
			{
				return $this->key;
			}
		};
		$client = $this->client($provider);
		$this->mock->append(new Response(200, [], '{"id":"env_1"}'), new Response(200, [], '{"id":"env_2"}'));

		$client->createEnvironment('env');
		$provider->key = 'sk-second';
		$client->createEnvironment('env');

		$this->assertSame('sk-first', $this->history[0]['request']->getHeaderLine('x-api-key'));
		$this->assertSame('sk-second', $this->history[1]['request']->getHeaderLine('x-api-key'));
	}

	public function testSessionTitleIsSanitizedAndBetaHeaderSent(): void
	{
		$client = $this->client();
		$this->mock->append(new Response(200, [], '{"id":"sesn_1"}'));

		$this->assertSame(['id' => 'sesn_1'], $client->createSession('agent_1', 'env_1', "Line\none\u{200B}"));

		$request = $this->history[0]['request'];
		$this->assertSame('https://api.test/v1/sessions', (string) $request->getUri());
		$this->assertSame(GuzzleManagedAgentsClient::MANAGED_AGENTS_BETA, $request->getHeaderLine('anthropic-beta'));
		$this->assertSame(
			['agent' => 'agent_1', 'environment_id' => 'env_1', 'title' => 'Line one'],
			json_decode((string) $request->getBody(), true),
		);
	}

	public function testSessionWithModelOverridesTheAgent(): void
	{
		$client = $this->client();
		$this->mock->append(new Response(200, [], '{"id":"sesn_1"}'));

		$client->createSession('agent_1', 'env_1', null, ['id' => 'claude-opus-5-5', 'effort' => 'max']);

		$this->assertSame(
			[
				'agent' => ['type' => 'agent_with_overrides', 'id' => 'agent_1', 'model' => ['id' => 'claude-opus-5-5', 'effort' => 'max']],
				'environment_id' => 'env_1',
			],
			json_decode((string) $this->history[0]['request']->getBody(), true),
		);
	}

	public function testUploadIsMultipartWithBothBetas(): void
	{
		$client = $this->client();
		$this->mock->append(new Response(200, [], '{"id":"file_1"}'));

		$client->uploadFile('a.csv', 'x,y', 'text/plain');

		$request = $this->history[0]['request'];
		$this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
		$this->assertStringContainsString(GuzzleManagedAgentsClient::FILES_API_BETA, $request->getHeaderLine('anthropic-beta'));
		$this->assertStringContainsString(GuzzleManagedAgentsClient::MANAGED_AGENTS_BETA, $request->getHeaderLine('anthropic-beta'));
	}

	public function testFailuresBecomeUserSafeExceptions(): void
	{
		$client = $this->client();
		$this->mock->append(
			new Response(529, [], '{"error":{"message":"internal detail"}}'),
			new ConnectException('cURL error 6 for https://api.test', new Request('GET', 'https://api.test')),
			new Response(200, [], 'not json'),
		);

		foreach (['HTTP 529', 'connection failed', 'invalid response'] as $detail) {
			try {
				$client->listSessionEvents('sesn_1');
				$this->fail('Expected ApiUnavailableException.');
			} catch (ApiUnavailableException $e) {
				$this->assertSame('aiChat.error.serviceUnavailable', $e->getTranslationKey());
				$this->assertSame(['detail' => $detail], $e->getTranslationParams());
				$this->assertStringNotContainsString('api.test', $e->getMessage());
			}
		}
	}

	/** Logging or metrics from outside (a DI decorator) without a subclass */
	public function testAddedMiddlewareSeesEveryRequest(): void
	{
		$mock = new MockHandler([new Response(200, [], '{"id":"env_1"}')]);
		$client = new class ($mock) extends GuzzleManagedAgentsClient {
			public function __construct(private readonly MockHandler $transport)
			{
				parent::__construct('sk-test', 'claude-test', 'https://api.test/v1/');
			}

			protected function createHandlerStack(): HandlerStack
			{
				$stack = parent::createHandlerStack();
				$stack->setHandler($this->transport);
				return $stack;
			}
		};
		$seen = [];
		$client->addMiddleware(Middleware::mapRequest(function (\Psr\Http\Message\RequestInterface $request) use (&$seen) {
			$seen[] = (string) $request->getUri();
			return $request;
		}), 'log');

		$client->createEnvironment('env');

		$this->assertSame(['https://api.test/v1/environments'], $seen);
	}

	/** Next to the transport: an error status is seen as a response, not as an exception */
	public function testMiddlewareSeesErrorResponsesBeforeHttpErrors(): void
	{
		$mock = new MockHandler([new Response(429, [], '{"error":{"message":"slow down"}}')]);
		$client = new class ($mock) extends GuzzleManagedAgentsClient {
			public function __construct(private readonly MockHandler $transport)
			{
				parent::__construct('sk-test', 'claude-test', 'https://api.test/v1/');
			}

			protected function createHandlerStack(): HandlerStack
			{
				$stack = parent::createHandlerStack();
				$stack->setHandler($this->transport);
				return $stack;
			}
		};
		$statuses = [];
		$client->addMiddleware(Middleware::mapResponse(function (\Psr\Http\Message\ResponseInterface $response) use (&$statuses) {
			$statuses[] = $response->getStatusCode();
			return $response;
		}));

		try {
			$client->createEnvironment('env');
		} catch (ApiUnavailableException) {
		}

		$this->assertSame([429], $statuses);
	}
}
