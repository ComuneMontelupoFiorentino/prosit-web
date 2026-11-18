<?php
declare(strict_types=1);

namespace Prosit\Auth;

use Prosit\Http\ApiException;
use Prosit\Http\Request;

/**
 * Punto unico di autenticazione per tutto Prosit.
 *
 * Nelle API:      $me = Auth::requireUser();          // 401 se non c'e' sessione Lizmap
 *                 Auth::requireGroup(['admins']);     // 403 se non nel gruppo
 * Nelle pagine:   $me = Auth::pageUser(['opnd']);     // pagina HTML di errore invece del JSON
 * Negli hook:     Auth::requireKey('meteo');          // chiave macchina
 */
final class Auth
{
    private static ?Identity $current = null;

    /** Identita' della richiesta: sessione Lizmap, poi chiave macchina, altrimenti anonimo. */
    public static function current(): Identity
    {
        if (self::$current !== null) {
            return self::$current;
        }
        // chi e' l'utente e in quali gruppi: lo dice Lizmap (Prosit API v2, /auth/me)
        $u = LizmapApi::read();
        if ($u !== null) {
            return self::$current = new Identity(
                type: 'session',
                login: $u['login'],
                email: $u['email'],
                firstname: $u['firstname'],
                lastname: $u['lastname'],
                organization: $u['organization'],
                groupIds: $u['groupIds'],
                groupNames: $u['groupNames']
            );
        }
        return self::$current = ApiKeys::identify() ?? Identity::anonymous();
    }

    /** True se l'identita' e' gia' stata calcolata in questa richiesta (per il log di accesso). */
    public static function isResolved(): bool
    {
        return self::$current !== null;
    }

    /** Solo per i test. */
    public static function reset(?Identity $as = null): void
    {
        self::$current = $as;
    }

    public static function requireAuthenticated(): Identity
    {
        $me = self::current();
        if (!$me->isAuthenticated()) {
            throw ApiException::unauthorized();
        }
        if ($me->isSessionUser() && Request::isUnsafe()) {
            Request::assertSameOrigin();
        }
        return $me;
    }

    /** Utente Lizmap collegato (le chiavi macchina non bastano). */
    public static function requireUser(): Identity
    {
        $me = self::current();
        if (!$me->isSessionUser()) {
            throw ApiException::unauthorized();
        }
        if (Request::isUnsafe()) {
            Request::assertSameOrigin();
        }
        return $me;
    }

    /** Regola di accesso (vedi Identity::allows). */
    public static function require(array $rule, string $message = 'Permessi insufficienti per questa operazione.'): Identity
    {
        $me = self::current();
        if (!$me->allows($rule)) {
            throw $me->isAuthenticated() ? ApiException::forbidden($message) : ApiException::unauthorized();
        }
        if ($me->isSessionUser() && Request::isUnsafe()) {
            Request::assertSameOrigin();
        }
        return $me;
    }

    public static function requireGroup(array $groups): Identity
    {
        self::requireUser();
        return self::require($groups, 'Il tuo utente non appartiene a un gruppo abilitato.');
    }

    /** Chiave macchina specifica (per gli hook). */
    public static function requireKey(string $name): Identity
    {
        $id = ApiKeys::identify($name);
        if ($id === null) {
            throw ApiException::unauthorized('Accesso non consentito: chiave o indirizzo non autorizzati.');
        }
        return self::$current = $id;
    }

    /**
     * Versione per pagine HTML: invece del JSON mostra una pagina di errore.
     * $groups vuoto = basta essere collegati.
     */
    public static function pageUser(array $groups = []): Identity
    {
        try {
            $me = self::requireUser();
            if ($groups !== [] && !$me->allows($groups)) {
                throw ApiException::forbidden('Il tuo utente non appartiene a un gruppo abilitato a questa pagina (' . implode(', ', $groups) . ').');
            }
            return $me;
        } catch (ApiException $e) {
            \Prosit\Http\Response::htmlError(
                $e->status,
                $e->status === 401 ? 'Accesso richiesto' : 'Accesso negato',
                $e->getMessage()
            );
        }
    }
}
