<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Doctrine;

use App\Entity\Configuration;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use KimaiPlugin\LogserverBundle\Client\LogSinkInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Meldet Änderungen an Einstellungen, Rollen/Berechtigungen und sicherheitsrelevanten
 * Benutzerfeldern an den Logserver.
 *
 * Es werden nur Namen und unkritische Werte gemeldet. Werte von Systemeinstellungen und
 * Benutzereinstellungen (können Zugangsdaten enthalten) sowie Passwörter, E-Mail-Adressen und
 * Token werden nie übertragen, nur die Tatsache der Änderung.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SettingsChangeListener
{
    private const TYPE = 'settings';

    /** Benutzerfelder, die gemeldet werden: Feld => true, wenn alter und neuer Wert mitgeschickt werden dürfen */
    private const USER_FIELDS = [
        'roles' => true,
        'enabled' => true,
        'totpEnabled' => true,
        'systemAccount' => true,
        'username' => false,
        'email' => false,
        'password' => false,
        'apiToken' => false,
    ];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $pending = [];

    public function __construct(
        private readonly LogSinkInterface $log,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        try {
            $uow = $args->getObjectManager()->getUnitOfWork();

            foreach ($uow->getScheduledEntityInsertions() as $entity) {
                $this->collect($entity, 'angelegt', []);
            }
            foreach ($uow->getScheduledEntityUpdates() as $entity) {
                $this->collect($entity, 'geändert', $uow->getEntityChangeSet($entity));
            }
            foreach ($uow->getScheduledEntityDeletions() as $entity) {
                $this->collect($entity, 'gelöscht', []);
            }
        } catch (\Throwable) {
            // nie die Datenbankoperation beeinträchtigen
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ($pending === []) {
            return;
        }

        try {
            $actor = $this->actor();
            foreach ($pending as [$message, $meta]) {
                $this->log->log('info', $message, $meta + $actor, self::TYPE);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function collect(object $entity, string $action, array $changes): void
    {
        if ($entity instanceof Configuration) {
            if ($action === 'geändert' && !$this->valueChanged($changes, 'value')) {
                return;
            }
            $name = (string) $entity->getName();
            $this->pending[] = ['Systemeinstellung ' . $action . ': ' . $name, ['setting' => $name, 'action' => $action]];

            return;
        }

        if ($entity instanceof RolePermission) {
            if ($action === 'geändert' && !$this->valueChanged($changes, 'allowed')) {
                return;
            }
            $role = (string) $entity->getRole()?->getName();
            $permission = (string) $entity->getPermission();
            $this->pending[] = [
                \sprintf('Berechtigung %s: %s / %s', $action, $role, $permission),
                ['role' => $role, 'permission' => $permission, 'allowed' => $entity->isAllowed(), 'action' => $action],
            ];

            return;
        }

        if ($entity instanceof Role) {
            if ($action === 'geändert') {
                return;
            }
            $name = (string) $entity->getName();
            $this->pending[] = ['Rolle ' . $action . ': ' . $name, ['role' => $name, 'action' => $action]];

            return;
        }

        if ($entity instanceof UserPreference) {
            // nur echte Änderungen eines Wertes; das Anlegen der Standardwerte ist kein Ereignis
            if ($action !== 'geändert' || !$this->valueChanged($changes, 'value')) {
                return;
            }
            $this->pending[] = [
                'Benutzereinstellung geändert: ' . $entity->getName(),
                ['setting' => $entity->getName(), 'target_user' => $entity->getUser()?->getUserIdentifier(), 'action' => $action],
            ];

            return;
        }

        if ($entity instanceof User) {
            $this->collectUser($entity, $action, $changes);
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function collectUser(User $user, string $action, array $changes): void
    {
        $name = $user->getUserIdentifier();

        if ($action !== 'geändert') {
            $this->pending[] = [
                'Benutzer ' . $action . ': ' . $name,
                ['target_user' => $name, 'roles' => $user->getRoles(), 'enabled' => $user->isEnabled(), 'action' => $action],
            ];

            return;
        }

        $meta = ['target_user' => $name, 'action' => $action];
        $fields = [];
        foreach (self::USER_FIELDS as $field => $withValues) {
            if (!$this->valueChanged($changes, $field)) {
                continue;
            }
            $fields[] = $field;
            if ($withValues) {
                $meta[$field . '_old'] = $changes[$field][0];
                $meta[$field . '_new'] = $changes[$field][1];
            }
        }
        if ($fields === []) {
            return; // z. B. nur lastLogin
        }
        $meta['fields'] = implode(',', $fields);
        $this->pending[] = ['Benutzer geändert: ' . $name . ' (' . $meta['fields'] . ')', $meta];
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function valueChanged(array $changes, string $field): bool
    {
        return isset($changes[$field]) && $changes[$field][0] != $changes[$field][1];
    }

    /**
     * @return array<string, string|null>
     */
    private function actor(): array
    {
        $request = $this->requestStack->getMainRequest();
        $user = $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier();

        return [
            'by' => $user ?? 'system',
            'source' => $request === null ? 'cli' : 'web',
            'ip' => $request?->getClientIp(),
        ];
    }
}
