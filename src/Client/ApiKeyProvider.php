<?php

declare(strict_types=1);

namespace ADT\AiChat\Client;

/**
 * Supplies the Anthropic API key at request time instead of at construction.
 *
 * For applications where the key is not one per server - e.g. every tenant
 * (clinic, company) enters its own in the settings. {@see GuzzleManagedAgentsClient}
 * asks for it on every request, so a long-running queue consumer picks up a
 * changed key without a restart.
 *
 * Keep in mind that agents, environments, sessions and files belong to the
 * workspace of the key they were created with: after a key change the agent has
 * to be provisioned again ({@see \ADT\AiChat\AgentProvisioner}).
 */
interface ApiKeyProvider
{
	/** Empty string = no key; the API then answers 401 like with a wrong one. */
	public function getApiKey(): string;
}
