<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

class LockEventSubscriber implements EventSubscriberInterface
{
    public const ENABLED = true;

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

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ('sulu_media.website.image.proxy' !== $route) {
            return;
        }

        $lock = $this->createLock($route, \rand(0, $this->max));
        $lock->acquire(true);

        $event->getRequest()->attributes->set('image_proxy_lock', $lock);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $lock = $event->getRequest()->attributes->get('image_proxy_lock');
        if (!$lock instanceof LockInterface) {
            return;
        }

        $lock->release();
    }

    protected function createLock(string $route, int $index): LockInterface
    {
        $store = new FlockStore($this->projectDirectory . '/var/lock');
        $factory = new LockFactory($store);

        $maxExecutionTime = \ini_get('max_execution_time');
        if ($maxExecutionTime <= 0) {
            $maxExecutionTime = 60;
        }

        return $factory->createLock($route . $index, (int) ($maxExecutionTime * 0.75));
    }
}
