<?php

declare(strict_types=1);

use ADT\AiChat\Mcp\McpEndpoint;
use ADT\AiChat\Mcp\McpServer;
use ADT\AiChat\Mcp\McpTokenAuthenticator;
use ADT\AiChat\Mcp\McpTokenException;
use ADT\AiChat\Mcp\McpTokenGenerator;
use ADT\AiChat\Mcp\McpTokenPolicy;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestMcpToken;
use ADT\AiChat\Tests\Support\Entity\TestOwner;
use ADT\AiChat\Tests\Support\StubQueryExecutor;
use ADT\AiChat\ToolHandler;

class McpEndpointTest extends \Codeception\Test\Unit
{
	private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}';

	private ChatTestEnvironment $env;

	/** Owners the policy refuses: id => 'signIn' | 'forbidden'. */
	private array $refused = [];

	private ?TestOwner $ranAs = null;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
		$this->refused = [];
		$this->ranAs = null;
	}

	private function endpoint(): McpEndpoint
	{
		$test = $this;
		$policy = new class ($test) implements McpTokenPolicy {
			public function __construct(private readonly McpEndpointTest $test)
			{
			}

			public function canSignIn(mixed $owner): bool
			{
				return ($this->test->refusal($owner) ?? '') !== 'signIn';
			}

			public function isAllowed(mixed $owner): bool
			{
				return ($this->test->refusal($owner) ?? '') !== 'forbidden';
			}

			public function runAs(mixed $owner, callable $handle): mixed
			{
				$this->test->ranAs($owner);

				return $handle();
			}
		};

		return new McpEndpoint(
			new McpServer(new ToolHandler(new StubQueryExecutor()), serverName: 'test'),
			new McpTokenAuthenticator($this->env->em, TestMcpToken::class, $policy),
		);
	}

	public function refusal(TestOwner $owner): ?string
	{
		return $this->refused[$owner->id] ?? null;
	}

	public function ranAs(TestOwner $owner): void
	{
		$this->ranAs = $owner;
	}

	private function token(?DateTimeImmutable $expiresAt = null, ?string $refuse = null): array
	{
		$owner = $this->env->owner();
		if ($refuse !== null) {
			$this->refused[$owner->id] = $refuse;
		}

		$raw = McpTokenGenerator::generate('test_');
		$token = (new TestMcpToken())->setName('t')->setTokenHash(McpTokenGenerator::hash($raw))->setExpiresAt($expiresAt);
		$token->owner = $owner;
		$this->env->em->persist($token);
		$this->env->em->flush();

		return [$raw, $token, $owner];
	}

	public function testValidTokenRunsTheServerAsItsOwner(): void
	{
		[$raw, $token, $owner] = $this->token();

		$response = $this->endpoint()->handle('POST', 'Bearer ' . $raw, self::INITIALIZE);

		$this->assertSame(200, $response->status);
		$this->assertSame('test', json_decode($response->body, true)['result']['serverInfo']['name']);
		$this->assertSame($owner, $this->ranAs);
		$this->assertNotNull($token->getLastUsedAt());
	}

	public function testRefusals(): void
	{
		[$expired] = $this->token(new DateTimeImmutable('-1 minute'));
		[$cannotSignIn] = $this->token(refuse: 'signIn');
		[$forbidden] = $this->token(refuse: 'forbidden');
		$endpoint = $this->endpoint();

		$this->assertSame(401, $endpoint->handle('POST', null, self::INITIALIZE)->status);
		$this->assertSame(401, $endpoint->handle('POST', 'Bearer nope', self::INITIALIZE)->status);
		$this->assertSame(401, $endpoint->handle('POST', 'Bearer ' . $expired, self::INITIALIZE)->status);
		$this->assertSame(401, $endpoint->handle('POST', 'Bearer ' . $cannotSignIn, self::INITIALIZE)->status);
		$this->assertSame(403, $endpoint->handle('POST', 'Bearer ' . $forbidden, self::INITIALIZE)->status);
		$this->assertSame(405, $endpoint->handle('GET', 'Bearer ' . $forbidden, '')->status);
		$this->assertNull($this->ranAs);
	}

	/** The same tokens authenticate your own API calls too; the reason picks your message. */
	public function testAuthenticatorTellsTheReason(): void
	{
		[$forbidden] = $this->token(refuse: 'forbidden');
		$authenticator = new McpTokenAuthenticator($this->env->em, TestMcpToken::class, new class implements McpTokenPolicy {
			public function canSignIn(mixed $owner): bool
			{
				return true;
			}

			public function isAllowed(mixed $owner): bool
			{
				return false;
			}

			public function runAs(mixed $owner, callable $handle): mixed
			{
				return $handle();
			}
		});

		try {
			$authenticator->authenticate('Bearer ' . $forbidden);
			$this->fail('Forbidden owner passed.');
		} catch (McpTokenException $e) {
			$this->assertSame(McpTokenException::FORBIDDEN, $e->reason);
			$this->assertSame(403, $e->getStatus());
		}
	}

	public function testGeneratedTokenAndHash(): void
	{
		$token = McpTokenGenerator::generate('nas_');

		$this->assertMatchesRegularExpression('/^nas_[0-9A-Za-z]{32}$/', $token);
		$this->assertSame(hash('sha256', $token), McpTokenGenerator::hash($token));
	}
}
