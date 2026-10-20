<?php

namespace App\Domain\Tenancy;

use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Models\Device;
use App\Models\School;
use App\Models\SchoolMembership;
use LogicException;

/**
 * Konteks sekolah untuk satu request (scoped binding). Diisi middleware ResolveSchoolMembership
 * setelah keanggotaan aktif tervalidasi. Mengakses konteks yang belum diisi melempar exception
 * (gagal tertutup), bukan mengembalikan null.
 */
final class TenantContext
{
    private ?School $school = null;

    private ?SchoolMembership $membership = null;

    private ?Device $device = null;

    /** True bila Super Admin (bukan anggota) sedang mengakses sekolah ini (D-21). */
    private bool $platformAccess = false;

    /** @var list<Role> */
    private array $roles = [];

    /**
     * @param  list<Role>  $roles
     */
    public function enter(School $school, SchoolMembership $membership, array $roles): void
    {
        $this->school = $school;
        $this->membership = $membership;
        $this->roles = $roles;
    }

    /**
     * Konteks akses Super Admin (D-21): bertindak sebagai Admin Sekolah tanpa menjadi anggota; keanggotaan yang
     * diberikan hanya objek di memori (tidak pernah disimpan).
     *
     * @param  list<Role>  $roles
     */
    public function enterPlatformAdmin(School $school, SchoolMembership $virtual, array $roles): void
    {
        $this->enter($school, $virtual, $roles);
        $this->platformAccess = true;
    }

    public function isPlatformAccess(): bool
    {
        return $this->platformAccess;
    }

    /** Konteks terminal: sekolah berasal dari perangkat yang lolos guard `device`; tidak ada keanggotaan/peran. */
    public function enterDevice(School $school, Device $device): void
    {
        $this->school = $school;
        $this->membership = null;
        $this->device = $device;
        $this->roles = [];
    }

    public function device(): Device
    {
        return $this->device ?? throw new LogicException('Konteks perangkat belum ditetapkan.');
    }

    public function clear(): void
    {
        $this->school = null;
        $this->membership = null;
        $this->device = null;
        $this->platformAccess = false;
        $this->roles = [];
    }

    public function isActive(): bool
    {
        return $this->school !== null;
    }

    public function school(): School
    {
        return $this->school ?? throw new LogicException('Konteks sekolah belum ditetapkan.');
    }

    public function membership(): SchoolMembership
    {
        return $this->membership ?? throw new LogicException('Konteks sekolah belum ditetapkan.');
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        $this->school();

        return $this->roles;
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return Permission::grantedTo($this->roles());
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
