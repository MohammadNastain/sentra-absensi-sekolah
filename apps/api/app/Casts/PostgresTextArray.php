<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast kolom PostgreSQL text[] <-> list<string>.
 *
 * Hanya mendukung elemen sederhana (tanpa koma, kurung kurawal, kutip, backslash, atau spasi),
 * yang cukup untuk kode enum seperti jenjang dan kode peran. Nilai lain ditolak, bukan di-escape diam-diam.
 *
 * @implements CastsAttributes<list<string>, mixed>
 */
class PostgresTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $inner = trim((string) $value, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$key} harus berupa array.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! preg_match('/^[A-Za-z0-9_-]+$/', $item)) {
                throw new InvalidArgumentException("Elemen {$key} tidak valid untuk text[].");
            }
        }

        return '{'.implode(',', $value).'}';
    }
}
