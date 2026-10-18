<?php

namespace App\Domain\Academic;

use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;

/**
 * Menerjemahkan pelanggaran constraint database yang bisa terjadi karena balapan (dua request bersamaan)
 * menjadi error API yang stabil. Validasi FormRequest menangkap kasus biasa lebih dulu dengan pesan per field;
 * ini jaring pengaman terakhir, bukan pengganti validasi.
 */
final class ConstraintViolations
{
    /** @var array<string, string> nama constraint unik → pesan */
    private const UNIQUE_MESSAGES = [
        'academic_years_school_name_unique' => 'Nama tahun ajaran sudah dipakai.',
        'classes_year_name_unique' => 'Nama kelas sudah dipakai pada tahun ajaran ini.',
        'teachers_employee_number_unique' => 'Nomor pegawai sudah dipakai guru lain.',
        'teachers_membership_id_unique' => 'Akun ini sudah ditautkan ke guru lain.',
    ];

    public static function translate(QueryException $e): ?ApiProblem
    {
        $state = $e->errorInfo[0] ?? null;
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($state === '23P01' && str_contains($message, 'academic_years_no_overlap')) {
            return ApiProblem::academicYearOverlap();
        }

        if ($state === '23505') {
            foreach (self::UNIQUE_MESSAGES as $constraint => $detail) {
                if (str_contains($message, $constraint)) {
                    return ApiProblem::duplicateValue($detail);
                }
            }
        }

        return null;
    }
}
