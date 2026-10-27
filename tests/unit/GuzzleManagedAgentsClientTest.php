<?php

declare(strict_types=1);

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

	private function client(): GuzzleManagedAgentsClient
	{
		$this->mock = new MockHandler();
		$this->history = [];
		$stack = HandlerStack::create($this->mock);
		$stack->push(Middleware::history($this->history));

		return new class ($stack) extends GuzzleManagedAgentsClient {
			public function __construct(private readonly HandlerStack $stack)
			{
				parent::__construct('sk-test', 'claude-test', 'https://api.test/v1/');
			}

			protected function createHttpClient(): ClientInterface
			{
				return new Client(['handler' => $this->stack, 'headers' => ['x-api-key' => $this->apiKey]]);
			}
		};
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
}
