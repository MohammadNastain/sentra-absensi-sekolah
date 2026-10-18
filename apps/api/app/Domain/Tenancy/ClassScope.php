<?php

namespace App\Domain\Tenancy;

use App\Domain\Identity\Role;
use App\Models\SchoolClass;
use App\Models\Teacher;

/**
 * Pembatasan data akademik per kelas (ARCHITECTURE §7): wali kelas hanya melihat kelas yang ia ampu
 * (guru aktif yang terhubung ke keanggotaannya) dan siswa penempatan berjalannya. Peran yang
 * juga Admin atau Kepala Sekolah tidak dibatasi (union peran).
 */
final class ClassScope
{
    /** @var list<string>|null */
    private ?array $ids = null;

    public function __construct(private readonly TenantContext $tenant) {}

    public function isRestricted(): bool
    {
        $roles = $this->tenant->roles();

        return in_array(Role::HomeroomTeacher, $roles, true)
            && ! in_array(Role::SchoolAdmin, $roles, true)
            && ! in_array(Role::Principal, $roles, true);
    }

    /**
     * Id kelas yang boleh dilihat; null bila tidak dibatasi.
     *
     * @return list<string>|null
     */
    public function classIds(): ?array
    {
        if (! $this->isRestricted()) {
            return null;
        }

        return $this->ids ??= array_values(array_map('strval', SchoolClass::query()
            ->whereIn('homeroom_teacher_id', Teacher::query()
                ->where('membership_id', $this->tenant->membership()->id)
                ->where('status', Teacher::STATUS_ACTIVE)
                ->select('id'))
            ->pluck('id')
            ->values()
            ->all()));
    }

    public function allows(string $classId): bool
    {
        $ids = $this->classIds();

        return $ids === null || in_array($classId, $ids, true);
    }
}
