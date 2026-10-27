<?php

declare(strict_types=1);

namespace ADT\AiChat\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_owner')]
class TestOwner
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;
}
