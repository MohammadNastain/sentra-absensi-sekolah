<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lapis 2 isolasi tenant (ARCHITECTURE §5): saat konteks sekolah aktif, setiap query model otomatis
 * dibatasi ke sekolah itu, dan pembuatan model untuk sekolah lain ditolak dengan exception.
 *
 * Penting untuk tabel yang punya policy RLS tambahan "baca milik sendiri" (school_memberships):
 * tanpa scope ini, query tanpa where school_id ikut memuat keanggotaan user di sekolah LAIN.
 * Pada konteks non-request (job, alur publik) scope tidak aktif; kode di sana wajib memfilter eksplisit.
 */
trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('school', function (Builder $query): void {
            $tenant = app(TenantContext::class);

            if ($tenant->isActive()) {
                $query->where($query->getModel()->getTable().'.school_id', $tenant->school()->id);
            }
        });

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class);

            if ($tenant->isActive() && $model->getAttribute('school_id') !== $tenant->school()->id) {
                throw new LogicException('Model tenant tidak boleh dibuat untuk sekolah selain konteks aktif.');
            }
        });
    }
}
