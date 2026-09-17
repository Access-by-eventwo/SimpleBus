<?php

namespace SimpleBus\DoctrineORMBridge\MessageBus;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SimpleBus\Message\Bus\Middleware\MessageBusMiddleware;
use Throwable;

class WrapsMessageHandlingInTransaction implements MessageBusMiddleware
{
    private ManagerRegistry $managerRegistry;

    private string $entityManagerName;

    public function __construct(ManagerRegistry $managerRegistry, string $entityManagerName)
    {
        $this->managerRegistry = $managerRegistry;
        $this->entityManagerName = $entityManagerName;
    }

    public function handle(object $message, callable $next): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->managerRegistry->getManager($this->entityManagerName);

        try {
            $entityManager->wrapInTransaction(
                function () use ($message, $next) {
                    $next($message);
                }
            );
        } catch (Throwable $error) {
            $this->managerRegistry->resetManager($this->entityManagerName);

            throw $error;
        }
    }
}
