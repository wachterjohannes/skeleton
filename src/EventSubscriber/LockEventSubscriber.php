<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

class LockEventSubscriber implements EventSubscriberInterface
{
    public const ENABLED = true;

    private ?LockInterface $lock = null;

    public function __construct(
        private string $projectDirectory,
        private int $max = 5,
    ) {
    }

    public static function getSubscribedEvents()
    {
        if (!self::ENABLED) {
            return;
        }

        yield KernelEvents::REQUEST => 'onKernelRequest';
        yield KernelEvents::RESPONSE => 'onKernelResponse';
    }

    public function onKernelRequest(RequestEvent $e): void
    {
        $route = $e->getRequest()->attributes->get('_route');
        if ('sulu_media.website.image.proxy' !== $route) {
            return;
        }

        $this->lock = $this->createLock($route, \rand(0, $this->max));
        $this->lock->acquire(true);
    }

    public function onKernelResponse(): void
    {
        $this->lock?->release();
    }

    protected function createLock(string $route, int $index): LockInterface
    {
        $store = new FlockStore($this->projectDirectory . '/var/lock');
        $factory = new LockFactory($store);

        return $factory->createLock($route . $index, 30);
    }
}
