<?php
declare(strict_types=1);

namespace Prosit\Auth;

/**
 * Chi sta facendo la richiesta.
 *   type = 'session'   utente Lizmap (cookie di sessione del browser)
 *          'apikey'    macchina/servizio con chiave (QField, stazione meteo...)
 *          'anonymous' nessuna credenziale
 *
 * I gruppi Lizmap hanno un id (es. "vigili") e un nome (es. "Polizia Municipale"):
 * nei permessi si puo' usare l'uno o l'altro, il confronto ignora maiuscole/minuscole.
 */
final class Identity
{
    /**
     * @param array<int,string> $groupIds
     * @param array<int,string> $groupNames
     */
    public function __construct(
        public readonly string $type,
        public readonly string $login = '',
        public readonly string $email = '',
        public readonly string $firstname = '',
        public readonly string $lastname = '',
        public readonly string $organization = '',
        public readonly array $groupIds = [],
        public readonly array $groupNames = [],
        public readonly ?string $keyName = null
    ) {
    }

    public static function anonymous(): self
    {
        return new self('anonymous');
    }

    public function isAuthenticated(): bool
    {
        return $this->type !== 'anonymous';
    }

    public function isSessionUser(): bool
    {
        return $this->type === 'session';
    }

    /** Nome leggibile: "Nome Cognome", altrimenti il login. */
    public function displayName(): string
    {
        $n = trim($this->firstname . ' ' . $this->lastname);
        return $n !== '' ? $n : $this->login;
    }

    /** Tutti i gruppi (id e nomi), in minuscolo, per i confronti. */
    public function groupKeys(): array
    {
        $all = [];
        foreach (array_merge($this->groupIds, $this->groupNames) as $g) {
            $all[mb_strtolower(trim((string) $g))] = true;
        }
        return array_keys($all);
    }

    public function inGroup(string $group): bool
    {
        return in_array(mb_strtolower(trim($group)), $this->groupKeys(), true);
    }

    /**
     * Regola di accesso: lista di "gettoni", basta che uno sia soddisfatto.
     *   '@public'        chiunque, anche senza login (usare con molta cautela)
     *   '@user'          qualunque utente Lizmap autenticato
     *   '@key:NOME'      la chiave macchina NOME
     *   'admins'         chi appartiene al gruppo (id o nome) indicato
     */
    public function allows(array $rule): bool
    {
        foreach ($rule as $token) {
            $token = (string) $token;
            if ($token === '@public') {
                return true;
            }
            if ($token === '@user' && $this->isSessionUser()) {
                return true;
            }
            if (str_starts_with($token, '@key:') && $this->type === 'apikey' && $this->keyName === substr($token, 5)) {
                return true;
            }
            if ($token !== '' && $token[0] !== '@' && $this->type !== 'anonymous' && $this->inGroup($token)) {
                return true;
            }
        }
        return false;
    }

    /** Valori lato server usabili nei campi forzati: @user.login, @user.name, @user.email, @now, @today. */
    public function placeholder(string $token): mixed
    {
        return match ($token) {
            '@user.login' => $this->login,
            '@user.name' => $this->displayName(),
            '@user.email' => $this->email,
            '@user.organization' => $this->organization,
            '@now' => date('c'),
            '@today' => date('Y-m-d'),
            default => $token,
        };
    }

    /** Rappresentazione pubblica (risposta di /auth/me). */
    public function toArray(): array
    {
        return [
            'authenticated' => $this->isAuthenticated(),
            'auth_type' => $this->type,
            'user' => [
                'login' => $this->login,
                'email' => $this->email,
                'firstname' => $this->firstname,
                'lastname' => $this->lastname,
                'organization' => $this->organization,
                'name' => $this->displayName(),
            ],
            'groups' => ['ids' => array_values($this->groupIds), 'names' => array_values($this->groupNames)],
        ];
    }
}
