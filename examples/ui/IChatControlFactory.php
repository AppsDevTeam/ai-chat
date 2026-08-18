<?php

declare(strict_types=1);

namespace App\AiChat\Ui;

/**
 * Nette DI generates the implementation - register it in config.neon:
 *
 *   services:
 *       - App\AiChat\Ui\IChatControlFactory
 */
interface IChatControlFactory
{
	public function create(int $userId): ChatControl;
}
