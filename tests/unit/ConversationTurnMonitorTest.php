<?php

declare(strict_types=1);

use ADT\AiChat\ConversationTurnMonitor;
use ADT\AiChat\Tests\Support\ChatTestEnvironment;
use ADT\AiChat\Tests\Support\Entity\TestConversation;

class ConversationTurnMonitorTest extends \Codeception\Test\Unit
{
	private ChatTestEnvironment $env;

	protected function _before(): void
	{
		$this->env = new ChatTestEnvironment();
	}

	public function testStopRequestIsReadFromTheDatabase(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->env->chat->enqueueMessage($conversation, 'Hi');
		$monitor = new ConversationTurnMonitor($this->env->em, $conversation, cancelCheckSeconds: 0);

		$this->assertFalse($monitor->isCancelled());

		// Another request stops the turn - the worker's entity does not see the change
		$this->env->em->createQueryBuilder()
			->update(TestConversation::class, 'c')
			->set('c.turnCancelled', ':now')
			->setParameter('now', new \DateTimeImmutable(), 'datetime_immutable')
			->getQuery()
			->execute();

		$this->assertTrue($monitor->isCancelled());
	}

	public function testDeletedConversationStopsTheTurn(): void
	{
		$conversation = $this->env->chat->createConversation($this->env->owner());
		$this->env->chat->enqueueMessage($conversation, 'Hi');
		$monitor = new ConversationTurnMonitor($this->env->em, $conversation, cancelCheckSeconds: 0);

		$this->env->em->getConnection()->executeStatement('DELETE FROM ' . $this->env->em->getClassMetadata(TestConversation::class)->getTableName());

		$this->assertTrue($monitor->isCancelled());
	}
}
