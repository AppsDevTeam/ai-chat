<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * The agent and environment a new session should be created with.
 *
 * $fingerprint identifies the agent's current system prompt and tools
 * ({@see AgentProvisioner::fingerprint()}); a session created with another one
 * runs on an outdated agent and is replaced. Null = not known, sessions are reused.
 */
class AgentResolution
{
	public function __construct(
		public readonly string $agentId,
		public readonly string $environmentId,
		public readonly ?string $fingerprint = null,
	) {
	}
}
