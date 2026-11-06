<?php

declare(strict_types=1);

namespace ADT\AiChat\Tool;

use ADT\AiChat\Http\Download;

/**
 * A tool that gives the user a file. {@see handle()} returns
 * `{type: export, token, filename, title?}` (plus anything the model should see) and
 * the widget renders a download button from it; the file itself is made only when
 * the user clicks it - {@see export()} gets the input and result stored with the
 * message, and runs in the web request of whoever downloads (check their permissions).
 */
interface ExportTool extends Tool
{
	/**
	 * @param array<string, mixed> $input the stored tool input
	 * @param array<string, mixed> $result the stored tool result (token, filename, ...)
	 * @throws \ADT\AiChat\Exception\AiChatException when the file cannot be made (or the user may not have it)
	 */
	public function export(array $input, array $result): Download;
}
