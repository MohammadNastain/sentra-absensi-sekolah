<?php

namespace App\Domain\Imports;

use App\Models\User;

/**
 * Memvalidasi dan menyimpan baris satu jenis impor. Satu instance dipakai untuk satu batch: keunikan diperiksa
 * dengan himpunan di memori, dan baris valid "memesan" nilainya agar duplikat di baris berikutnya ditolak.
 */
interface ImportRowProcessor
{
    /**
     * @param  array<string, string|null>  $row
     * @return array{normalized: array<string, mixed>|null, errors: list<array{field: string, message: string}>}
     */
    public function validate(array $row): array;

    /**
     * Menyimpan baris yang baru saja lolos validate(); dipanggil di dalam transaksi commit.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function persist(array $normalized, User $actor): void;
}
