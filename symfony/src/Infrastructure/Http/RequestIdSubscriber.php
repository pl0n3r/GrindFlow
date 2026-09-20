<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber
{
    private const KEY = '_grindflow_request_id';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function begin(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::KEY, bin2hex(random_bytes(12)));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $id = $event->getRequest()->attributes->get(self::KEY);
        if (is_string($id)) {
            $event->getResponse()->headers->set('X-Request-Id', $id);
        }
    }
}
