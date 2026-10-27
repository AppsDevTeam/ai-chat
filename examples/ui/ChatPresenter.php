<?php

declare(strict_types=1);

namespace App\AiChat\Ui;

use ADT\AiChat\AttachmentUpload;
use ADT\AiChat\ChatService;
use ADT\AiChat\Http\ChatEndpoint;
use App\Model\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\FileUpload;

/**
 * A page with the chat widget (assets/ai-chat.js). The signals only translate the
 * HTTP request to ChatEndpoint calls - all logic, ownership scoping and the
 * tool-data whitelist live in the package.
 *
 * Protect the page with whatever authorization your project uses; the endpoint
 * scopes everything to the owner passed in.
 */
class ChatPresenter extends Presenter
{
	public function __construct(
		private readonly ChatEndpoint $endpoint,
		private readonly ChatService $chat,
		private readonly EntityManagerInterface $em,
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();

		if (!$this->getUser()->isLoggedIn()) {
			$this->redirect(':Sign:in');
		}
	}

	public function renderDefault(): void
	{
		$attachments = $this->chat->getOptions()->attachments;

		$this->template->config = [
			'urls' => [
				'conversations' => $this->link('conversations!'),
				'load' => $this->link('load!'),
				'send' => $this->link('send!'),
				'poll' => $this->link('poll!'),
				'delete' => $this->link('delete!'),
				'export' => $this->link('export!'),
			],
			'accept' => $attachments->getAcceptAttribute(),
			'maxAttachments' => $attachments->maxCount,
			'maxAttachmentBytes' => $attachments->maxBytes,
			'locale' => 'en',
			// 'labels' => [...] to localize the widget
		];
	}

	public function handleConversations(): void
	{
		$this->sendJson($this->endpoint->conversations($this->owner()));
	}

	public function handleLoad(): void
	{
		$this->sendJson($this->endpoint->load($this->owner(), $this->param('conversationId')));
	}

	public function handleSend(): void
	{
		$this->assertPost();

		$files = $this->getHttpRequest()->getFile('attachments') ?? [];
		$uploads = array_map(
			AttachmentUpload::fromNetteFileUpload(...),
			array_filter(is_array($files) ? $files : [$files], static fn($f): bool => $f instanceof FileUpload),
		);

		$this->sendJson($this->endpoint->send(
			$this->owner(),
			$this->param('conversationId'),
			(string) $this->getHttpRequest()->getPost('message'),
			array_values($uploads),
		));
	}

	public function handlePoll(): void
	{
		$this->sendJson($this->endpoint->poll($this->owner(), $this->param('conversationId'), $this->param('afterId')));
	}

	public function handleDelete(): void
	{
		$this->assertPost();
		$this->sendJson($this->endpoint->delete($this->owner(), $this->param('conversationId')));
	}

	public function handleExport(): void
	{
		$export = $this->endpoint->export(
			$this->owner(),
			$this->param('conversationId'),
			$this->param('messageId'),
			(string) $this->getHttpRequest()->getQuery('token'),
		) ?? $this->error();

		foreach ($export->getHeaders() as $name => $value) {
			$this->getHttpResponse()->setHeader($name, $value);
		}

		$this->sendResponse(new CallbackResponse(static fn() => $export->stream()));
	}

	private function owner(): User
	{
		// The owner field is an association, so pass the entity (a reference is enough).
		return $this->em->getReference(User::class, $this->getUser()->getId());
	}

	/** Reads (POST) FormData first, then the query string. */
	private function param(string $name): string
	{
		$request = $this->getHttpRequest();

		return (string) ($request->getPost($name) ?? $request->getQuery($name) ?? '');
	}

	private function assertPost(): void
	{
		if (!$this->getHttpRequest()->isMethod('POST')) {
			$this->error('POST required', 405);
		}
	}
}
