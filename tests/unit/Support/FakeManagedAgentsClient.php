<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support;

use ADT\AiChat\Client\ManagedAgentsClient;
use ADT\AiChat\Exception\AiChatException;

/**
 * Scriptable fake of the Managed Agents API for tests.
 *
 * Events are served in "frames": every listSessionEvents() call returns all
 * frames released so far, mimicking the cumulative event list of a session.
 * By default one frame is released per poll; sendSessionEvents() can be told to
 * fail, and everything sent is recorded for assertions.
 */
class FakeManagedAgentsClient implements ManagedAgentsClient
{
	/** @var list<list<array<string, mixed>>> frames of events to release, one per poll */
	public array $frames = [];

	/** @var list<array<string, mixed>> events present before the turn (history) */
	public array $history = [];

	/** @var list<list<array<string, mixed>>> everything passed to sendSessionEvents() */
	public array $sentEvents = [];

	/** @var list<string> */
	public array $createdSessionIds = [];

	/** @var list<array{id: string, effort?: string}|null> the model override of every created session */
	public array $createdSessionModels = [];

	/** Fail user.message on sessions that were NOT created by this fake (stale ones). */
	public bool $failOnUserMessage = false;

	/** Fail every user.message, fresh sessions included. */
	public bool $failAllUserMessages = false;

	public int $listCalls = 0;

	public int $createdAgents = 0;

	/** @var list<string> agent ids passed to updateAgent() */
	public array $updatedAgents = [];

	/** Fail agent creation and updates (e.g. an invalid API key). */
	public bool $failAgentCalls = false;

	/** @var list<array<string, mixed>|null> the multiagent field of every created or updated agent */
	public array $agentMultiagent = [];

	/** @var list<string> statuses returned by retrieveSession() one by one; then "idle" */
	public array $sessionStatuses = [];

	public int $retrieveCalls = 0;

	/** Page size cap of listSessionEvents(), to exercise paging. */
	public int $maxPageSize = 1000;

	/** @var list<array{limit: int, page: ?string, order: ?string}> */
	public array $listRequests = [];

	private bool $turnStarted = false;

	private int $released = 0;

	private int $sessionCounter = 0;

	/**
	 * Scripts the next turn: the events released so far become history (as in a
	 * real session) and the new frames wait for the next user message.
	 *
	 * @param list<list<array<string, mixed>>> $frames
	 */
	public function script(array $frames): void
	{
		if ($this->turnStarted) {
			for ($i = 0; $i < $this->released; $i++) {
				$this->history = array_merge($this->history, $this->frames[$i]);
			}
		}

		$this->frames = $frames;
		$this->released = 0;
		$this->turnStarted = false;
	}

	public function createAgent(string $name, string $systemPrompt, array $tools, ?array $multiagent = null): array
	{
		$this->agentMultiagent[] = $multiagent;
		if ($this->failAgentCalls) {
			throw new \RuntimeException('401 invalid x-api-key (test)');
		}
		$this->createdAgents++;

		return ['id' => 'agent_fake', 'version' => 1];
	}

	public function updateAgent(string $agentId, string $systemPrompt, array $tools, ?array $multiagent = null): array
	{
		$this->agentMultiagent[] = $multiagent;
		if ($this->failAgentCalls) {
			throw new \RuntimeException('401 invalid x-api-key (test)');
		}
		$this->updatedAgents[] = $agentId;

		return ['id' => $agentId, 'version' => 2];
	}

	public function createEnvironment(string $name): array
	{
		return ['id' => 'env_fake'];
	}

	public function createSession(string $agentId, string $environmentId, ?string $title = null, ?array $model = null): array
	{
		$id = 'sesn_' . (++$this->sessionCounter);
		$this->createdSessionIds[] = $id;
		$this->createdSessionModels[] = $model;

		return ['id' => $id, 'status' => 'idle'];
	}

	public function sendSessionEvents(string $sessionId, array $events): array
	{
		$isUserMessage = ($events[0]['type'] ?? '') === 'user.message';

		if ($isUserMessage && $this->failAllUserMessages) {
			throw new AiChatException('Session refused the message (test).');
		}

		// Stale sessions (created outside this fake) refuse the message; a fresh one
		// created by the fake accepts it - the retry-with-a-fresh-session path.
		if (
			$this->failOnUserMessage
			&& $isUserMessage
			&& !in_array($sessionId, $this->createdSessionIds, true)
		) {
			throw new AiChatException('Session is stuck in requires_action (test).');
		}

		if ($isUserMessage) {
			$this->turnStarted = true;
		}

		$this->sentEvents[] = $events;

		return ['type' => 'ok'];
	}

	public function retrieveSession(string $sessionId): array
	{
		$this->retrieveCalls++;

		return ['id' => $sessionId, 'status' => array_shift($this->sessionStatuses) ?? 'idle'];
	}

	public function uploadFile(string $filename, string $contents, string $mediaType): array
	{
		return [
			'id' => 'file_fake_' . md5($filename . $contents),
			'filename' => $filename,
			'mime_type' => $mediaType,
			'size_bytes' => strlen($contents),
		];
	}

	public function listSessionEvents(string $sessionId, int $limit = 1000, ?string $page = null, ?string $order = null): array
	{
		$this->listCalls++;
		$this->listRequests[] = ['limit' => $limit, 'page' => $page, 'order' => $order];

		// Before a user message is delivered only the history exists - frames are
		// the events of the running turn. This also keeps the snapshot of a RETRY
		// from consuming the reply frames prematurely. A frame is released per first
		// page only, so paging back reads a stable list.
		if ($this->turnStarted && $page === null) {
			$this->released = min($this->released + 1, count($this->frames));
		}

		$data = $this->history;
		if ($this->turnStarted) {
			for ($i = 0; $i < $this->released; $i++) {
				$data = array_merge($data, $this->frames[$i]);
			}
		}

		if ($order === 'desc') {
			$data = array_reverse($data);
		}

		$offset = (int) $page;
		$limit = min($limit, $this->maxPageSize);

		return [
			'data' => array_slice($data, $offset, $limit),
			'next_page' => $offset + $limit < count($data) ? (string) ($offset + $limit) : null,
		];
	}
}
