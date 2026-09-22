<?php

namespace SimpleBus\DoctrineORMBridge\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Proxy;
use PHPUnit\Framework\TestCase;
use SimpleBus\DoctrineORMBridge\EventListener\CollectsEventsFromEntities;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Entity\EventRecordingEntity;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityAboutToBeRemoved;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityChanged;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityChangedPreUpdate;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityCreated;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityCreatedPrePersist;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Event\EntityNotDirty;
use SimpleBus\DoctrineORMBridge\Tests\PHPUnitTestServiceContainer\PHPUnit\TestCaseWithEntityManager;
use SimpleBus\Message\Recorder\ContainsRecordedMessages;

class CollectsEventsFromEntitiesTest extends TestCase
{
    use TestCaseWithEntityManager;

    private CollectsEventsFromEntities $eventSubscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventSubscriber = new CollectsEventsFromEntities();
        $this->getEventManager()->addEventListener([Events::preFlush, Events::postFlush], $this->eventSubscriber);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromPersistedEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();

        $this->persistAndFlush($entity);
        $this->assertContainsEquals(new EntityCreated(), $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromModifiedEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);
        $this->eraseRecordedMessages();

        $entity->changeSomething();
        $this->persistAndFlush($entity);

        $this->assertContainsEquals(new EntityChanged(), $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromRemovedEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);
        $this->eraseRecordedMessages();

        $entity->prepareForRemoval();
        $this->removeAndFlush($entity);

        $this->assertEquals([new EntityAboutToBeRemoved()], $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromNotDirtyEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);
        $this->eraseRecordedMessages();

        $entity->recordMessageWithoutStateChange();
        $this->persistAndFlush($entity);

        $this->assertEquals([new EntityNotDirty()], $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromPrePersistLifecycleCallbacksOfEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);

        $this->assertContainsEquals(new EntityCreatedPrePersist(), $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    /**
     * @test
     */
    public function itCollectsEventsFromPreUpdateLifecycleCallbacksOfDirtyEntitiesAndErasesThemAfterwards(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);
        $this->eraseRecordedMessages();

        $entity->changeSomethingWithoutRecording();
        $this->persistAndFlush($entity);

        $this->assertEquals([new EntityChangedPreUpdate()], $this->eventSubscriber->recordedMessages());

        $this->assertEntityHasNoRecordedEvents($entity);
    }

    public function testUsesProxyInitializationContract(): void
    {
        $event = new EntityChanged();
        $readOnce = self::once();
        $eraseOnce = self::once();
        $twice = self::exactly(2);
        $proxy = $this->createMock(RecordingProxy::class);
        $initialization = $proxy->expects($twice)->method('__isInitialized');
        $initialization->willReturnOnConsecutiveCalls(false, true);
        $messages = $proxy->expects($readOnce)->method('recordedMessages');
        $messages->willReturn([$event]);
        $proxy->expects($eraseOnce)->method('eraseMessages');

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getIdentityMap')->willReturn([[$proxy]]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $eventArgs = new PostFlushEventArgs($entityManager);

        $this->eventSubscriber->postFlush($eventArgs);
        $beforeInitialization = $this->eventSubscriber->recordedMessages();

        self::assertSame([], $beforeInitialization);

        $this->eventSubscriber->postFlush($eventArgs);
        $afterInitialization = $this->eventSubscriber->recordedMessages();

        self::assertSame([$event], $afterInitialization);
    }

    public function testSkipsUninitializedProxiesAndCollectsAfterInitialization(): void
    {
        $entity = new EventRecordingEntity();
        $this->persistAndFlush($entity);
        $this->eraseRecordedMessages();
        $id = $entity->getId();
        $entityManager = $this->getEntityManager();
        $entityManager->clear();
        $proxy = $entityManager->getReference(EventRecordingEntity::class, $id);

        $entityManager->flush();

        $initialized = $proxy->__isInitialized();
        $events = $this->eventSubscriber->recordedMessages();

        self::assertFalse($initialized);
        self::assertSame([], $events);

        $proxy->changeSomething();
        $entityManager->flush();

        $initialized = $proxy->__isInitialized();
        $events = $this->eventSubscriber->recordedMessages();
        $expected = new EntityChanged();

        self::assertTrue($initialized);
        self::assertContainsEquals($expected, $events);

        $this->assertEntityHasNoRecordedEvents($proxy);
    }

    /**
     * @return string[]
     */
    protected function getEntityDirectories(): array
    {
        return [
            __DIR__.'/Fixtures/Entity',
        ];
    }

    private function persistAndFlush(object $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    private function eraseRecordedMessages(): void
    {
        $this->eventSubscriber->eraseMessages();
    }

    private function assertEntityHasNoRecordedEvents(ContainsRecordedMessages $entity): void
    {
        $this->assertSame([], $entity->recordedMessages());
    }

    private function removeAndFlush(object $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }
}

interface RecordingProxy extends Proxy, ContainsRecordedMessages
{
}
