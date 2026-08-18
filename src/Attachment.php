<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * A file attached to a user message, already uploaded to the Anthropic Files API
 * ({@see Client\ManagedAgentsClient::uploadFile()}). The runner turns it into an
 * image or document content block of the user.message event, so the model reads
 * the file directly from its context - no filesystem tools are needed.
 *
 * Supported media types follow the Files API content-block rules: image/jpeg,
 * image/png, image/gif and image/webp become image blocks; application/pdf and
 * text/plain become document blocks. Other text formats (CSV, Markdown, logs)
 * should be uploaded as text/plain - the filename keeps the original extension.
 */
final class Attachment
{
	public function __construct(
		public readonly string $fileId,
		public readonly string $mediaType,
		public readonly string $filename = '',
	) {
	}

	/**
	 * The content block for the user.message event.
	 *
	 * @return array<string, mixed>
	 */
	public function toContentBlock(): array
	{
		$type = str_starts_with($this->mediaType, 'image/') ? 'image' : 'document';

		return [
			'type' => $type,
			'source' => ['type' => 'file', 'file_id' => $this->fileId],
		];
	}
}
