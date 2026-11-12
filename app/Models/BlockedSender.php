<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['pattern'])]
class BlockedSender extends Model
{
    /** "@dominio.cl" bloquea todo el dominio; una dirección completa bloquea solo esa. */
    public static function matches(iterable $patterns, ?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return false;
        }
        foreach ($patterns as $pattern) {
            $pattern = mb_strtolower($pattern);
            if ($pattern === $email || (str_starts_with($pattern, '@') && str_ends_with($email, $pattern))) {
                return true;
            }
        }

        return false;
    }
}
