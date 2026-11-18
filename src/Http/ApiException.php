<?php
declare(strict_types=1);

namespace Prosit\Http;

/** Errore "previsto": diventa una risposta JSON con lo stato indicato. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $m, array $d = []): self { return new self(400, $m, $d); }
    public static function unauthorized(string $m = 'Sessione Lizmap assente o scaduta: accedi a Lizmap e riprova.'): self { return new self(401, $m); }
    public static function forbidden(string $m = 'Permessi insufficienti per questa operazione.'): self { return new self(403, $m); }
    public static function notFound(string $m = 'Risorsa non trovata.'): self { return new self(404, $m); }
    public static function methodNotAllowed(array $allowed): self { return new self(405, 'Metodo non consentito.', ['allowed' => $allowed]); }
    public static function unprocessable(string $m, array $d = []): self { return new self(422, $m, $d); }
}
