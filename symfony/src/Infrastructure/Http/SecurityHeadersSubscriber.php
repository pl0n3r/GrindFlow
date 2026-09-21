<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -200)]
final class SecurityHeadersSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Respect stricter policies on private binary content while keeping
        // a conservative default for public pages and JSON responses.
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // Apply to redirects and exceptions as well as successful private responses.
        // Login errors and authenticated routes must never be stored by a browser
        // or an intermediary, even if a controller omits its own cache policy.
        if (preg_match('#^/(?:login|logout|admin|organizations|api/admin)(?:/|$)#D', $event->getRequest()->getPathInfo()) === 1) {
            $headers->set('Cache-Control', 'no-store, private');
        }
    }
}
