<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

use GuzzleHttp\HandlerStack;

/**
 * Guzzle middlewares of a client added from outside - logging, metrics or retries without
 * a subclass, e.g. by a decorator of the DI container:
 *
 *   decorator:
 *       ADT\AiChat\Client\GuzzleManagedAgentsClient:
 *           setup:
 *               - addMiddleware(@App\ApiLogger::middleware('anthropic'), apiLog)
 *
 * Add them before the first request - the HTTP client is created once. They sit next to the
 * transport (below http_errors and allow_redirects): a logger sees every response with its
 * status and body before an error status becomes an exception, and every redirect step.
 */
trait GuzzleMiddlewares
{
	/** @var list<array{callable, string}> */
	private array $middlewares = [];

	/** @param callable(callable): callable $middleware */
	public function addMiddleware(callable $middleware, string $name = ''): void
	{
		$this->middlewares[] = [$middleware, $name];
	}

	protected function createHandlerStack(): HandlerStack
	{
		$stack = HandlerStack::create();
		// push = next to the transport (Guzzle's unshift would put it on top, above http_errors)
		foreach ($this->middlewares as [$middleware, $name]) {
			$stack->push($middleware, $name);
		}

		return $stack;
	}
}
