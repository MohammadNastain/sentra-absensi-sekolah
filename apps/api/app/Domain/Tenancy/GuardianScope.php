<?php

namespace App\Domain\Tenancy;

use App\Domain\Identity\Role;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;

/**
 * Pembatasan portal orang tua (ADR 0011 §4): wali hanya melihat anaknya sendiri. "Anak" = siswa aktif yang ditautkan ke
 * wali berstatus aktif dan berkeanggotaan akun ini, dengan peran `guardian` di keanggotaan tersebut. Peran lain yang
 * dimiliki orang yang sama (mis. guru) tidak memberi hak di sini, dan hak wali tidak menambah hak peran lain.
 *
 * Izin (`school.portal.*`) dan entitlement tetap diperiksa terpisah; kelas ini hanya menjawab "anak milik siapa".
 */
final class GuardianScope
{
    /** @var list<string>|null */
    private ?array $childIds = null;

    /** Keanggotaan yang dipakai mengisi cache; bila berganti (instance bertahan antar request), cache dibuang. */
    private ?string $cachedFor = null;

    public function __construct(private readonly TenantContext $tenant) {}

    public function isGuardian(): bool
    {
        return in_array(Role::Guardian, $this->tenant->roles(), true);
    }

    /**
     * Id siswa yang menjadi anak akun ini (kosong bila bukan wali atau tanpa anak aktif).
     *
     * @return list<string>
     */
    public function childIds(): array
    {
        if (! $this->isGuardian()) {
            return [];
        }

        $current = $this->tenant->membership()->id;
        if ($this->cachedFor !== $current) {
            $this->childIds = null;
            $this->cachedFor = $current;
        }

        return $this->childIds ??= array_values(StudentGuardian::query()
            ->whereIn('guardian_id', Guardian::query()
                ->where('membership_id', $current)
                ->where('status', Guardian::STATUS_ACTIVE)
                ->select('id'))
            ->whereIn('student_id', Student::query()->where('status', Student::STATUS_ACTIVE)->select('id'))
            ->pluck('student_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all());
    }

    /** Siswa ini anak akun ini? */
    public function owns(string $studentId): bool
    {
        return in_array($studentId, $this->childIds(), true);
    }
}
