<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Entity\IdentityUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class StaffSecurityActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof IdentityUser) {
            return;
        }

        try {
            $this->db->executeStatement(
                <<<'SQL'
                    UPDATE gf_identity_users
                    SET last_access_at = :now
                    WHERE id = :id AND platform_role IN ('admin','editor')
                    SQL,
                ['now' => gmdate('Y-m-d H:i:s'), 'id' => $user->id()],
            );
        } catch (\Throwable) {
            $this->logger->warning('staff_access_metadata_write_failed');
        }
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !$request->isMethod('POST') || $request->getPathInfo() !== '/login') {
            return;
        }

        $email = $request->request->all()['email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $id = $this->db->fetchOne(
                <<<'SQL'
                    SELECT id FROM gf_identity_users
                    WHERE email = :email AND platform_role IN ('admin','editor')
                    LIMIT 1
                    SQL,
                ['email' => strtolower(trim($email))],
            );
            if (!is_string($id) || $id === '') {
                return;
            }
            $this->db->insert('gf_ops_staff_login_failures', [
                'user_id' => $id,
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $this->logger->warning('staff_access_metadata_write_failed');
        }
    }
}
