<?php

declare(strict_types=1);

namespace ADT\AiChat;

use ADT\AiChat\Exception\AiChatException;

/**
 * Which files a user may attach to a message and how they are uploaded to the
 * Files API.
 *
 * Text formats (CSV, Markdown, logs) are uploaded as text/plain - a document
 * content block accepts no other text media type; the filename keeps the original
 * extension. Browsers report CSV under all kinds of types (application/vnd.ms-excel
 * on Windows), hence the fallback by extension.
 */
class AttachmentPolicy
{
	public const DEFAULT_MEDIA_TYPES = [
		'image/png' => 'image/png',
		'image/jpeg' => 'image/jpeg',
		'image/gif' => 'image/gif',
		'image/webp' => 'image/webp',
		'application/pdf' => 'application/pdf',
		'text/plain' => 'text/plain',
		'text/csv' => 'text/plain',
		'text/markdown' => 'text/plain',
	];

	public const DEFAULT_EXTENSION_MEDIA_TYPES = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'pdf' => 'application/pdf',
		'txt' => 'text/plain',
		'csv' => 'text/plain',
		'md' => 'text/plain',
		'log' => 'text/plain',
	];

	/**
	 * @param int $maxCount files per message; 0 disables attachments
	 * @param int $maxBytes per file - the Files API takes more, but PDFs and images
	 *        in the context are expensive
	 * @param array<string, string> $mediaTypes reported content type => uploaded media type
	 * @param array<string, string> $extensionMediaTypes lowercase extension => uploaded media type
	 */
	public function __construct(
		public readonly int $maxCount = 4,
		public readonly int $maxBytes = 10 * 1024 * 1024,
		public readonly array $mediaTypes = self::DEFAULT_MEDIA_TYPES,
		public readonly array $extensionMediaTypes = self::DEFAULT_EXTENSION_MEDIA_TYPES,
	) {
	}

	/**
	 * @param array<string, mixed> $options the constructor arguments by name
	 */
	public static function fromArray(array $options): self
	{
		return new self(...$options);
	}

	/**
	 * The `accept` attribute for the file input.
	 */
	public function getAcceptAttribute(): string
	{
		$extensions = array_map(static fn(string $extension): string => '.' . $extension, array_keys($this->extensionMediaTypes));

		return implode(',', array_unique([...array_keys($this->mediaTypes), ...$extensions]));
	}

	/**
	 * Validates the uploads of one message.
	 *
	 * @param list<AttachmentUpload> $uploads
	 * @return list<array{upload: AttachmentUpload, mediaType: string}>
	 * @throws AiChatException with a message for the user
	 */
	public function check(array $uploads): array
	{
		if (count($uploads) > $this->maxCount) {
			throw AiChatException::translatable(
				sprintf('You can attach at most %d files per message.', $this->maxCount),
				'aiChat.error.tooManyAttachments',
				['max' => $this->maxCount],
			);
		}

		$checked = [];
		foreach ($uploads as $upload) {
			if (!$upload->ok) {
				throw self::uploadFailed($upload);
			}

			if ($upload->size > $this->maxBytes) {
				$megabytes = (int) floor($this->maxBytes / 1024 / 1024);
				throw AiChatException::translatable(
					sprintf('File "%s" is too large. The limit is %d MB.', $upload->filename, $megabytes),
					'aiChat.error.attachmentTooLarge',
					['name' => $upload->filename, 'max' => $megabytes],
				);
			}

			$mediaType = $this->resolveMediaType($upload);
			if ($mediaType === null) {
				throw AiChatException::translatable(
					sprintf('File type of "%s" is not supported. Allowed: images (PNG, JPEG, GIF, WebP), PDF and text files (TXT, CSV, MD).', $upload->filename),
					'aiChat.error.attachmentType',
					['name' => $upload->filename],
				);
			}

			$checked[] = ['upload' => $upload, 'mediaType' => $mediaType];
		}

		return $checked;
	}

	public function resolveMediaType(AttachmentUpload $upload): ?string
	{
		return $this->mediaTypes[$upload->contentType ?? '']
			?? $this->extensionMediaTypes[strtolower(pathinfo($upload->filename, PATHINFO_EXTENSION))]
			?? null;
	}

	public static function uploadFailed(AttachmentUpload $upload): AiChatException
	{
		return AiChatException::translatable(
			sprintf('Upload of "%s" failed. Please try again.', $upload->filename),
			'aiChat.error.attachmentUploadFailed',
			['name' => $upload->filename],
		);
	}
}
