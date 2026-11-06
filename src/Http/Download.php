<?php

declare(strict_types=1);

namespace ADT\AiChat\Http;

/**
 * A file behind a download button of the widget ({@see ChatEndpoint::export()}): send
 * the headers first, then call {@see stream()} from a streamed response.
 */
interface Download
{
	/**
	 * @return array<string, string>
	 */
	public function getHeaders(): array;

	/**
	 * @param resource|null $out defaults to php://output
	 */
	public function stream($out = null): void;
}
