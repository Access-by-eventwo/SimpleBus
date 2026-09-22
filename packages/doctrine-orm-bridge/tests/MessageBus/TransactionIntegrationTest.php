<?php

namespace SimpleBus\DoctrineORMBridge\Tests\MessageBus;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Error;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleBus\DoctrineORMBridge\MessageBus\WrapsMessageHandlingInTransaction;
use SimpleBus\DoctrineORMBridge\Tests\EventListener\Fixtures\Entity\EventRecordingEntity;
use stdClass;
use Throwable;

class TransactionIntegrationTest extends TestCase
{
    private Connection $connection;

    private EntityManager $entityManager;

    /** @var ManagerRegistry&MockObject */
    private ManagerRegistry $registry;

    private WrapsMessageHandlingInTransaction $middleware;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
        $this->entityManager = new EntityManager($this->connection, $configuration);
        $metadata = $this->entityManager->getClassMetadata(EventRecordingEntity::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([$metadata]);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $getManager = $this->registry->method('getManager');
        $getManager->with('commands')->willReturn($this->entityManager);
        $this->middleware = new WrapsMessageHandlingInTransaction($this->registry, 'commands');
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->connection->close();
    }

    public function testFlushesAndCommits(): void
    {
        $message = new stdClass();
        $entity = new EventRecordingEntity();
        $never = self::never();
        $this->registry->expects($never)->method('resetManager');

        $this->middleware->handle($message, function (object $received) use ($message, $entity): void {
            $active = $this->connection->isTransactionActive();

            self::assertSame($message, $received);
            self::assertTrue($active);

            $entity->changeSomething();
            $this->entityManager->persist($entity);
        });

        $value = $this->connection->fetchOne('SELECT something FROM EventRecordingEntity');
        $active = $this->connection->isTransactionActive();
        $open = $this->entityManager->isOpen();

        self::assertSame('changed value', $value);
        self::assertFalse($active);
        self::assertTrue($open);
    }

    public function testRollsBackOnException(): void
    {
        $error = new RuntimeException('Handler failed');

        $this->assertRollback($error);
    }

    public function testRollsBackOnError(): void
    {
        $error = new Error('Handler failed');

        $this->assertRollback($error);
    }

    public function testResetsManagerWhenFlushFails(): void
    {
        $this->connection->executeStatement("CREATE TRIGGER reject_insert BEFORE INSERT ON EventRecordingEntity BEGIN SELECT RAISE(ABORT, 'Rejected'); END");
        $message = new stdClass();
        $once = self::once();
        $resetManager = $this->registry->expects($once)->method('resetManager');
        $resetManager->with('commands');

        try {
            $this->middleware->handle($message, function (): void {
                $entity = new EventRecordingEntity();
                $this->entityManager->persist($entity);
            });
            self::fail('The flush must fail');
        } catch (Exception) {
            $active = $this->connection->isTransactionActive();
            $open = $this->entityManager->isOpen();
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM EventRecordingEntity');

            self::assertFalse($active);
            self::assertFalse($open);
            self::assertSame(0, (int) $count);
        }
    }

    public function testNestedHandlingDoesNotCommitOuterTransaction(): void
    {
        $message = new stdClass();
        $never = self::never();
        $this->registry->expects($never)->method('resetManager');
        $this->connection->beginTransaction();

        $this->middleware->handle($message, function (): void {
            $entity = new EventRecordingEntity();
            $this->entityManager->persist($entity);
        });

        $level = $this->connection->getTransactionNestingLevel();
        $beforeRollback = $this->connection->fetchOne('SELECT COUNT(*) FROM EventRecordingEntity');
        $this->connection->rollBack();
        $afterRollback = $this->connection->fetchOne('SELECT COUNT(*) FROM EventRecordingEntity');

        self::assertSame(1, $level);
        self::assertSame(1, (int) $beforeRollback);
        self::assertSame(0, (int) $afterRollback);
    }

    private function assertRollback(Throwable $error): void
    {
        $message = new stdClass();
        $once = self::once();
        $resetManager = $this->registry->expects($once)->method('resetManager');
        $resetManager->with('commands');

        try {
            $this->middleware->handle($message, function () use ($error): void {
                $entity = new EventRecordingEntity();
                $this->entityManager->persist($entity);
                $this->entityManager->flush();

                throw $error;
            });
            self::fail('The handler must fail');
        } catch (Throwable $actual) {
            self::assertSame($error, $actual);
        }

        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM EventRecordingEntity');
        $active = $this->connection->isTransactionActive();
        $open = $this->entityManager->isOpen();

        self::assertSame(0, (int) $count);
        self::assertFalse($active);
        self::assertFalse($open);
    }
}
