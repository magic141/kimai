<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\EventSubscriber;

use KimaiPlugin\LogserverBundle\Client\LogSinkInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Meldet Anmeldungen, Abmeldungen, 2FA und Benutzerwechsel (Impersonation) an den Logserver.
 *
 * Erfolgreiche Anmeldungen werden nur für die Web-Firewall gemeldet: Die stateless-API-Firewall
 * löst das Ereignis bei jedem Aufruf aus. Fehlgeschlagene API-Anmeldungen werden gemeldet.
 */
final class AuthSubscriber implements EventSubscriberInterface
{
    private const WEB_FIREWALL = 'secured_area';
    private const TYPE = 'auth';

    public function __construct(private readonly LogSinkInterface $log)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            'security.switch_user' => 'onSwitchUser',
            'scheb_two_factor.authentication.success' => 'onTwoFactorSuccess',
            'scheb_two_factor.authentication.failure' => 'onTwoFactorFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        try {
            if ($event->getFirewallName() !== self::WEB_FIREWALL) {
                return;
            }
            $user = $event->getUser()->getUserIdentifier();
            $this->log->log('info', 'Anmeldung erfolgreich: ' . $user, [
                'user' => $user,
                'authenticator' => self::shortName($event->getAuthenticator()),
            ] + self::requestMeta($event->getRequest()), self::TYPE);
        } catch (\Throwable) {
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        try {
            $user = '';
            $passport = $event->getPassport();
            if ($passport !== null && $passport->hasBadge(UserBadge::class)) {
                /** @var UserBadge $badge */
                $badge = $passport->getBadge(UserBadge::class);
                $user = $badge->getUserIdentifier();
            }
            $api = $event->getFirewallName() !== self::WEB_FIREWALL;
            $this->log->log('warn', ($api ? 'API-Anmeldung fehlgeschlagen: ' : 'Anmeldung fehlgeschlagen: ') . ($user !== '' ? $user : '(unbekannt)'), [
                'user' => $user,
                'firewall' => $event->getFirewallName(),
                'reason' => self::shortName($event->getException()),
                'authenticator' => self::shortName($event->getAuthenticator()),
            ] + self::requestMeta($event->getRequest()), self::TYPE);
        } catch (\Throwable) {
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        try {
            $user = $event->getToken()?->getUser()?->getUserIdentifier() ?? '';
            if ($user === '') {
                return;
            }
            $this->log->log('info', 'Abmeldung: ' . $user, ['user' => $user] + self::requestMeta($event->getRequest()), self::TYPE);
        } catch (\Throwable) {
        }
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        try {
            $token = $event->getToken();
            $by = $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : ($token?->getUserIdentifier() ?? '');
            $target = $event->getTargetUser()->getUserIdentifier();
            $this->log->log('warn', \sprintf('Benutzerwechsel: %s als %s', $by !== '' ? $by : '(unbekannt)', $target), [
                'user' => $by,
                'target' => $target,
            ] + self::requestMeta($event->getRequest()), self::TYPE);
        } catch (\Throwable) {
        }
    }

    /**
     * @param object $event Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent
     */
    public function onTwoFactorSuccess(object $event): void
    {
        $this->twoFactor($event, 'info', '2FA erfolgreich: ');
    }

    /**
     * @param object $event Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent
     */
    public function onTwoFactorFailure(object $event): void
    {
        $this->twoFactor($event, 'warn', '2FA fehlgeschlagen: ');
    }

    private function twoFactor(object $event, string $level, string $prefix): void
    {
        try {
            if (!method_exists($event, 'getToken') || !method_exists($event, 'getRequest')) {
                return;
            }
            $token = $event->getToken();
            $user = $token instanceof TokenInterface ? ($token->getUser()?->getUserIdentifier() ?? '') : '';
            $request = $event->getRequest();
            $this->log->log($level, $prefix . ($user !== '' ? $user : '(unbekannt)'), [
                'user' => $user,
            ] + ($request instanceof Request ? self::requestMeta($request) : []), self::TYPE);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function requestMeta(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'ip' => $request->getClientIp(),
            'path' => $request->getPathInfo(),
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 200),
        ];
    }

    private static function shortName(?object $object): string
    {
        return $object === null ? '' : (new \ReflectionClass($object))->getShortName();
    }
}
