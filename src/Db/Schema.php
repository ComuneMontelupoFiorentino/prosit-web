<?php
declare(strict_types=1);

namespace Prosit\Db;

/** Nomi di tabelle "schema.tabella" scritti in modo sicuro nelle query (usato da bin/prosit check). */
final class Schema
{
    /** 'schema.tabella' -> ['schema','tabella'] ('public' se manca lo schema). */
    public static function split(string $relation): array
    {
        $parts = explode('.', $relation, 2);
        return count($parts) === 2 ? $parts : ['public', $parts[0]];
    }

    public static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public static function quoteRelation(string $relation): string
    {
        [$s, $t] = self::split($relation);
        return self::quoteIdent($s) . '.' . self::quoteIdent($t);
    }
}
