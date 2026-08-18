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

	/** Fail user.message on sessions that were NOT created by this fake (stale ones). */
	public bool $failOnUserMessage = false;

	/** Fail every user.message, fresh sessions included. */
	public bool $failAllUserMessages = false;

	public int $listCalls = 0;

	private bool $turnStarted = false;

	private int $released = 0;

	private int $sessionCounter = 0;

	public function createAgent(string $name, string $systemPrompt, array $tools): array
	{
		return ['id' => 'agent_fake', 'version' => 1];
	}

	public function updateAgent(string $agentId, string $systemPrompt, array $tools): array
	{
		return ['id' => $agentId, 'version' => 2];
	}

	public function createEnvironment(string $name): array
	{
		return ['id' => 'env_fake'];
	}

	public function createSession(string $agentId, string $environmentId, ?string $title = null): array
	{
		$id = 'sesn_' . (++$this->sessionCounter);
		$this->createdSessionIds[] = $id;

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

	public function uploadFile(string $filename, string $contents, string $mediaType): array
	{
		return [
			'id' => 'file_fake_' . md5($filename . $contents),
			'filename' => $filename,
			'mime_type' => $mediaType,
			'size_bytes' => strlen($contents),
		];
	}

	public function listSessionEvents(string $sessionId, int $limit = 1000, ?int $page = null): array
	{
		$this->listCalls++;

		// Before a user message is delivered only the history exists - frames are
		// the events of the running turn. This also keeps collectEventIds() of a
		// RETRY from consuming the reply frames prematurely.
		if (!$this->turnStarted) {
			return ['data' => $this->history];
		}

		$this->released = min($this->released + 1, count($this->frames));

		$data = $this->history;
		for ($i = 0; $i < $this->released; $i++) {
			$data = array_merge($data, $this->frames[$i]);
		}

		return ['data' => $data];
	}
}
