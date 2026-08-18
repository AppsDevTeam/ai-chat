<?php

declare(strict_types=1);

namespace App\AiChat\Ui;

use Nette\Application\UI\Presenter;

/**
 * Minimal page hosting the chat. Protect it with whatever authorization your
 * project uses - the control itself only scopes data to the passed user id.
 */
class ChatPresenter extends Presenter
{
	public function __construct(
		private readonly IChatControlFactory $chatControlFactory,
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

	protected function createComponentChat(): ChatControl
	{
		return $this->chatControlFactory->create((int) $this->getUser()->getId());
	}
}
