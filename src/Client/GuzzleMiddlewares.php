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
		// unshift = next to the transport; reversed, so that the first one added stays on top
		foreach (array_reverse($this->middlewares) as [$middleware, $name]) {
			$stack->unshift($middleware, $name);
		}

		return $stack;
	}
}
