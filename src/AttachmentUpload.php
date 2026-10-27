<?php

declare(strict_types=1);

namespace ADT\AiChat;

/**
 * A file the user attached to a message, as received by the web request - before
 * it is uploaded to the Files API. Framework neutral; build it from your request
 * object, or use {@see fromNetteFileUpload()}.
 */
final class AttachmentUpload
{
	/**
	 * @param string $filename sanitized name, sent to the Files API and shown in the UI
	 * @param ?string $contentType type reported by the browser (or detected)
	 * @param bool $ok false when the upload itself failed (partial, too big for PHP, ...)
	 */
	public function __construct(
		public readonly string $filename,
		public readonly string $contents,
		public readonly ?string $contentType,
		public readonly int $size,
		public readonly bool $ok = true,
	) {
	}

	public static function fromNetteFileUpload(\Nette\Http\FileUpload $upload): self
	{
		$filename = $upload->getSanitizedName();

		return new self(
			$filename !== '' ? $filename : 'attachment',
			$upload->isOk() ? (string) $upload->getContents() : '',
			$upload->getContentType(),
			(int) $upload->getSize(),
			$upload->isOk(),
		);
	}
}
