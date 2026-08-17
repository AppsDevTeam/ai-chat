<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * The agent and environment a new session should be created with.
 */
class AgentResolution
{
	public function __construct(
		public readonly string $agentId,
		public readonly string $environmentId,
	) {
	}
}
