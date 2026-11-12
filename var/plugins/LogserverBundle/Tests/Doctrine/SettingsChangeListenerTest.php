<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Tests\Doctrine;

use App\Entity\Configuration;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use KimaiPlugin\LogserverBundle\Doctrine\SettingsChangeListener;
use KimaiPlugin\LogserverBundle\Tests\RecordingSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SettingsChangeListenerTest extends TestCase
{
    private RecordingSink $sink;
    private TokenStorage $tokens;
    private RequestStack $requests;
    private SettingsChangeListener $listener;

    protected function setUp(): void
    {
        $this->sink = new RecordingSink();
        $this->tokens = new TokenStorage();
        $this->requests = new RequestStack();
        $this->listener = new SettingsChangeListener($this->sink, $this->tokens, $this->requests);
    }

    /**
     * @param list<object>                                               $insertions
     * @param array<int, array{0: object, 1: array<string, mixed>}>      $updates    Entität + ChangeSet
     * @param list<object>                                               $deletions
     */
    private function flush(array $insertions = [], array $updates = [], array $deletions = []): void
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn(array_map(static fn (array $u): object => $u[0], $updates));
        $uow->method('getScheduledEntityDeletions')->willReturn($deletions);
        $uow->method('getEntityChangeSet')->willReturnCallback(static function (object $entity) use ($updates): array {
            foreach ($updates as [$candidate, $changes]) {
                if ($candidate === $entity) {
                    return $changes;
                }
            }

            return [];
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->listener->onFlush(new OnFlushEventArgs($em));
        $this->listener->postFlush(new PostFlushEventArgs($em));
    }

    private function login(string $name): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($name, null), 'secured_area'));
        $this->requests->push(Request::create('/admin/system-config/', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.7']));
    }

    private function configuration(string $name, string $value): Configuration
    {
        return (new Configuration())->setName($name)->setValue($value);
    }

    public function testChangedSystemSettingIsLoggedWithoutValues(): void
    {
        $this->login('admin');
        $config = $this->configuration('mailer_dsn', 'smtp://user:geheim@mail.example.org');
        $this->flush([], [[$config, ['value' => ['smtp://alt', 'smtp://user:geheim@mail.example.org']]]]);

        self::assertCount(1, $this->sink->entries);
        $entry = $this->sink->entries[0];
        self::assertSame('settings', $entry['type']);
        self::assertSame('Systemeinstellung geändert: mailer_dsn', $entry['message']);
        self::assertSame('mailer_dsn', $entry['meta']['setting']);
        self::assertSame('admin', $entry['meta']['by']);
        self::assertSame('web', $entry['meta']['source']);
        self::assertSame('198.51.100.7', $entry['meta']['ip']);
        self::assertStringNotContainsString('geheim', json_encode($entry));
    }

    public function testUnchangedSystemSettingIsIgnored(): void
    {
        $config = $this->configuration('a', 'x');
        $this->flush([], [[$config, ['value' => ['x', 'x']]]]);

        self::assertSame([], $this->sink->entries);
    }

    public function testSystemSettingCreatedAndDeleted(): void
    {
        $this->flush([$this->configuration('neu', '1')], [], [$this->configuration('weg', '1')]);

        self::assertSame('Systemeinstellung angelegt: neu', $this->sink->entries[0]['message']);
        self::assertSame('Systemeinstellung gelöscht: weg', $this->sink->entries[1]['message']);
    }

    public function testSourceIsCliWithoutRequest(): void
    {
        $this->flush([$this->configuration('neu', '1')]);

        self::assertSame('cli', $this->sink->entries[0]['meta']['source']);
        self::assertSame('system', $this->sink->entries[0]['meta']['by']);
    }

    public function testNothingIsLoggedWhenFlushHasNoRelevantChanges(): void
    {
        $this->flush();

        self::assertSame([], $this->sink->entries);
    }

    public function testPermissionChange(): void
    {
        $this->login('admin');
        $role = (new Role())->setName('ROLE_TEAMLEAD');
        $permission = (new RolePermission())->setRole($role)->setPermission('delete_user')->setAllowed(true);
        $this->flush([], [[$permission, ['allowed' => [false, true]]]]);

        $entry = $this->sink->entries[0];
        self::assertSame('Berechtigung geändert: ROLE_TEAMLEAD / delete_user', $entry['message']);
        self::assertSame('ROLE_TEAMLEAD', $entry['meta']['role']);
        self::assertSame('delete_user', $entry['meta']['permission']);
        self::assertTrue($entry['meta']['allowed']);
    }

    public function testRoleCreatedButNotRenamedEvents(): void
    {
        $role = (new Role())->setName('ROLE_CUSTOM');
        $this->flush([$role], [[$role, ['name' => ['a', 'b']]]]);

        self::assertCount(1, $this->sink->entries);
        self::assertSame('Rolle angelegt: ROLE_CUSTOM', $this->sink->entries[0]['message']);
    }

    public function testUserRolesAndEnabledAreLoggedWithValues(): void
    {
        $this->login('admin');
        $user = new User();
        $user->setUserIdentifier('alice');
        $this->flush([], [[$user, [
            'roles' => [['ROLE_USER'], ['ROLE_USER', 'ROLE_ADMIN']],
            'enabled' => [true, false],
            'lastLogin' => [null, new \DateTime()],
        ]]]);

        $entry = $this->sink->entries[0];
        self::assertSame('Benutzer geändert: alice (roles,enabled)', $entry['message']);
        self::assertSame('alice', $entry['meta']['target_user']);
        self::assertSame(['ROLE_USER'], $entry['meta']['roles_old']);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $entry['meta']['roles_new']);
        self::assertFalse($entry['meta']['enabled_new']);
        self::assertSame('admin', $entry['meta']['by']);
    }

    public function testUserPasswordAndEmailChangeWithoutValues(): void
    {
        $user = new User();
        $user->setUserIdentifier('alice');
        $this->flush([], [[$user, [
            'password' => ['$2y$old', '$2y$new'],
            'email' => ['alt@example.org', 'neu@example.org'],
            'apiToken' => ['a', 'b'],
        ]]]);

        $entry = $this->sink->entries[0];
        self::assertSame('email,password,apiToken', $entry['meta']['fields']);
        $encoded = json_encode($entry);
        self::assertStringNotContainsString('$2y$', $encoded);
        self::assertStringNotContainsString('example.org', $encoded);
    }

    public function testUserUpdateWithOnlyLastLoginIsIgnored(): void
    {
        $user = new User();
        $user->setUserIdentifier('alice');
        $this->flush([], [[$user, ['lastLogin' => [null, new \DateTime()]]]]);

        self::assertSame([], $this->sink->entries);
    }

    public function testUserCreatedAndDeleted(): void
    {
        $user = new User();
        $user->setUserIdentifier('bob');
        $this->flush([$user], [], [$user]);

        self::assertSame('Benutzer angelegt: bob', $this->sink->entries[0]['message']);
        self::assertSame('Benutzer gelöscht: bob', $this->sink->entries[1]['message']);
    }

    public function testUserPreferenceChangeIsLoggedWithoutValue(): void
    {
        $user = new User();
        $user->setUserIdentifier('alice');
        $preference = (new UserPreference('language'))->setUser($user)->setValue('de');
        $this->flush([], [[$preference, ['value' => ['en', 'de']]]]);

        $entry = $this->sink->entries[0];
        self::assertSame('Benutzereinstellung geändert: language', $entry['message']);
        self::assertSame('alice', $entry['meta']['target_user']);
        self::assertStringNotContainsString('"de"', json_encode($entry['meta']));
    }

    public function testNewUserPreferencesAreIgnored(): void
    {
        $preference = (new UserPreference('language'))->setValue('de');
        $this->flush([$preference]);

        self::assertSame([], $this->sink->entries);
    }

    public function testNothingIsLoggedBeforePostFlush(): void
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([$this->configuration('a', '1')]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->listener->onFlush(new OnFlushEventArgs($em));

        self::assertSame([], $this->sink->entries, 'erst nach erfolgreichem Flush melden');
    }

    public function testBrokenUnitOfWorkNeverThrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willThrowException(new \RuntimeException('kaputt'));

        $this->listener->onFlush(new OnFlushEventArgs($em));
        $this->listener->postFlush(new PostFlushEventArgs($em));

        self::assertSame([], $this->sink->entries);
    }
}
