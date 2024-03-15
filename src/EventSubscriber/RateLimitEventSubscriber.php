<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

class RateLimitEventSubscriber implements EventSubscriberInterface
{
    public const ENABLED = false;

    public function __construct(
        private CacheItemPoolInterface $appCache,
    ) {
    }

    public static function getSubscribedEvents()
    {
        if (!self::ENABLED) {
            return [];
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

        $this->createRateLimiter($route)->reserve()->wait();
    }

    public function onKernelResponse(ResponseEvent $e): void
    {
        $route = $e->getRequest()->attributes->get('_route');
        if ('sulu_media.website.image.proxy' !== $route) {
            return;
        }

        $this->createRateLimiter($route)->consume();
    }

    protected function createRateLimiter(string $route): LimiterInterface
    {
        $factory = new RateLimiterFactory([
            'id' => 'route',
            'policy' => 'token_bucket',
            'limit' => 1,
            'rate' => [
                'interval' => '1 minute',
                'amount' => 1,
            ],
        ], new CacheStorage($this->appCache));

        return $factory->create($route);
    }
}
