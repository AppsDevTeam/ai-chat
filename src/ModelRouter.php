<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * Picks the model of a new conversation from its first message - a quick command to a
 * small fast model, an analysis to a large one ({@see ModelSelection}).
 *
 * Asked only for the first message and only when the user did not pick a model
 * himself: a session cannot switch models, a later switch would start a new session
 * with the transcript. Runs in the web request before the message is queued - keep it
 * fast (rules, a local classifier). The text is the user's, not pseudonymized.
 */
interface ModelRouter
{
	/**
	 * @return string|null a model id of the {@see ModelSelection}; null or an unknown id = the default
	 */
	public function route(string $userMessage): ?string;
}
