<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Tests\EventSubscriber;

use KimaiPlugin\LogserverBundle\EventSubscriber\AuthSubscriber;
use KimaiPlugin\LogserverBundle\Tests\RecordingSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

final class AuthSubscriberTest extends TestCase
{
    private RecordingSink $sink;
    private AuthSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->sink = new RecordingSink();
        $this->subscriber = new AuthSubscriber($this->sink);
    }

    private function request(): Request
    {
        return Request::create('/de/login_check', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => "Browser/1.0\x00"]);
    }

    private function successEvent(string $firewall, string $user = 'alice'): LoginSuccessEvent
    {
        $inMemory = new InMemoryUser($user, null, ['ROLE_USER']);

        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user, static fn () => $inMemory)),
            new UsernamePasswordToken($inMemory, $firewall, ['ROLE_USER']),
            $this->request(),
            null,
            $firewall
        );
    }

    public function testSubscribesToTheExpectedEvents(): void
    {
        $events = array_keys(AuthSubscriber::getSubscribedEvents());

        self::assertContains(LoginSuccessEvent::class, $events);
        self::assertContains(LoginFailureEvent::class, $events);
        self::assertContains(LogoutEvent::class, $events);
        self::assertContains('security.switch_user', $events);
        self::assertContains('scheb_two_factor.authentication.success', $events);
        self::assertContains('scheb_two_factor.authentication.failure', $events);
    }

    public function testWebLoginIsLogged(): void
    {
        $this->subscriber->onLoginSuccess($this->successEvent('secured_area'));

        self::assertCount(1, $this->sink->entries);
        $entry = $this->sink->entries[0];
        self::assertSame('info', $entry['level']);
        self::assertSame('auth', $entry['type']);
        self::assertSame('Anmeldung erfolgreich: alice', $entry['message']);
        self::assertSame('alice', $entry['meta']['user']);
        self::assertSame('203.0.113.9', $entry['meta']['ip']);
        self::assertSame('/de/login_check', $entry['meta']['path']);
        self::assertStringStartsWith('Browser/1.0', $entry['meta']['user_agent']);
        self::assertNotEmpty($entry['meta']['authenticator']);
    }

    public function testApiLoginSuccessIsIgnored(): void
    {
        $this->subscriber->onLoginSuccess($this->successEvent('api'));

        self::assertSame([], $this->sink->entries);
    }

    public function testWebLoginFailureIsLoggedAsWarning(): void
    {
        $event = new LoginFailureEvent(
            new BadCredentialsException(),
            $this->createMock(AuthenticatorInterface::class),
            $this->request(),
            null,
            'secured_area',
            new SelfValidatingPassport(new UserBadge('mallory', static fn () => new InMemoryUser('mallory', null)))
        );
        $this->subscriber->onLoginFailure($event);

        $entry = $this->sink->entries[0];
        self::assertSame('warn', $entry['level']);
        self::assertSame('Anmeldung fehlgeschlagen: mallory', $entry['message']);
        self::assertSame('mallory', $entry['meta']['user']);
        self::assertSame('BadCredentialsException', $entry['meta']['reason']);
        self::assertSame('secured_area', $entry['meta']['firewall']);
        self::assertSame('203.0.113.9', $entry['meta']['ip']);
    }

    public function testApiLoginFailureIsLogged(): void
    {
        $event = new LoginFailureEvent(new BadCredentialsException(), $this->createMock(AuthenticatorInterface::class), $this->request(), null, 'api', null);
        $this->subscriber->onLoginFailure($event);

        self::assertSame('API-Anmeldung fehlgeschlagen: (unbekannt)', $this->sink->entries[0]['message']);
        self::assertSame('api', $this->sink->entries[0]['meta']['firewall']);
    }

    public function testLogout(): void
    {
        $inMemory = new InMemoryUser('alice', null);
        $this->subscriber->onLogout(new LogoutEvent($this->request(), new UsernamePasswordToken($inMemory, 'secured_area')));

        self::assertSame('Abmeldung: alice', $this->sink->entries[0]['message']);
    }

    public function testLogoutWithoutTokenIsIgnored(): void
    {
        $this->subscriber->onLogout(new LogoutEvent($this->request(), null));

        self::assertSame([], $this->sink->entries);
    }

    public function testUserSwitchIsLoggedAsWarning(): void
    {
        $admin = new InMemoryUser('admin', null);
        $target = new InMemoryUser('alice', null);
        $original = new UsernamePasswordToken($admin, 'secured_area');
        $switch = new SwitchUserToken($target, 'secured_area', [], $original);
        $this->subscriber->onSwitchUser(new SwitchUserEvent($this->request(), $target, $switch));

        $entry = $this->sink->entries[0];
        self::assertSame('warn', $entry['level']);
        self::assertSame('Benutzerwechsel: admin als alice', $entry['message']);
        self::assertSame('admin', $entry['meta']['user']);
        self::assertSame('alice', $entry['meta']['target']);
    }

    public function testTwoFactorEvents(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('alice', null), 'secured_area');
        $request = $this->request();
        $event = new class($token, $request) {
            public function __construct(private $token, private $request)
            {
            }

            public function getToken()
            {
                return $this->token;
            }

            public function getRequest()
            {
                return $this->request;
            }
        };

        $this->subscriber->onTwoFactorSuccess($event);
        $this->subscriber->onTwoFactorFailure($event);

        self::assertSame('2FA erfolgreich: alice', $this->sink->entries[0]['message']);
        self::assertSame('info', $this->sink->entries[0]['level']);
        self::assertSame('2FA fehlgeschlagen: alice', $this->sink->entries[1]['message']);
        self::assertSame('warn', $this->sink->entries[1]['level']);
    }

    public function testBrokenEventsNeverThrow(): void
    {
        $this->subscriber->onTwoFactorSuccess(new \stdClass());

        self::assertSame([], $this->sink->entries);
    }
}
