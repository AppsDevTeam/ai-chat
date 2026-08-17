<?php

declare(strict_types=1);

// Standalone run (composer install in this package), or running while installed
// as a dependency of a project.
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
	if (is_file($autoload)) {
		require $autoload;
		break;
	}
}
