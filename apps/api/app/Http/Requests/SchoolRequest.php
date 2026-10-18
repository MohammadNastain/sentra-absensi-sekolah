<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dasar untuk request data milik sekolah: ID sekolah dari konteks tenant (bukan dari input), dan pembeda
 * buat (POST) versus ubah sebagian (PATCH) agar satu kelas aturan melayani keduanya.
 */
abstract class SchoolRequest extends FormRequest
{
    protected function schoolId(): string
    {
        return app(TenantContext::class)->school()->id;
    }

    protected function isUpdate(): bool
    {
        return $this->isMethod('PATCH');
    }

    /**
     * Pada PATCH, field boleh tidak dikirim; yang dikirim tetap divalidasi penuh.
     *
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    protected function optionalOnUpdate(array $rules): array
    {
        return $this->isUpdate() ? ['sometimes', ...$rules] : $rules;
    }
}
