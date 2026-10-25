<?php

namespace App\Domain\Tenancy;

use App\Domain\Identity\Role;
use App\Models\ClassEnrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingSlot;

/**
 * Pembatasan LMS per kelas-mapel (ADR 0010 §2). Admin Sekolah mengelola semua dan Kepala Sekolah melihat semua; guru
 * hanya pasangan (kelas, mapel) yang ada di jadwal mengajarnya; siswa hanya kelas tempat ia terdaftar saat ini.
 * Peran gabungan mengikuti union: bila salah satu peran tak dibatasi, hasilnya tak dibatasi.
 *
 * Izin (`school.lms.*`) dan entitlement tetap diperiksa terpisah; kelas ini hanya menjawab "konten milik siapa".
 */
final class LmsScope
{
    /** @var list<array{class_id: string, subject_id: string}>|null */
    private ?array $pairs = null;

    /** @var list<string>|null */
    private ?array $classIds = null;

    public function __construct(private readonly TenantContext $tenant) {}

    /** Admin mengelola semua konten sekolah. */
    public function managesAll(): bool
    {
        return in_array(Role::SchoolAdmin, $this->tenant->roles(), true);
    }

    /** Admin dan Kepala Sekolah melihat semua konten sekolah. */
    public function viewsAll(): bool
    {
        $roles = $this->tenant->roles();

        return in_array(Role::SchoolAdmin, $roles, true) || in_array(Role::Principal, $roles, true);
    }

    /**
     * Pasangan kelas-mapel yang diajar guru aktif ini (kosong bila bukan guru atau tanpa jadwal).
     *
     * @return list<array{class_id: string, subject_id: string}>
     */
    public function teachingPairs(): array
    {
        if (! in_array(Role::Teacher, $this->tenant->roles(), true)) {
            return [];
        }

        return $this->pairs ??= array_values(TeachingSlot::query()
            ->whereIn('teacher_id', Teacher::query()
                ->where('membership_id', $this->tenant->membership()->id)
                ->where('status', Teacher::STATUS_ACTIVE)
                ->select('id'))
            ->select(['class_id', 'subject_id'])
            ->distinct()
            ->get()
            ->map(fn (TeachingSlot $slot) => ['class_id' => (string) $slot->class_id, 'subject_id' => (string) $slot->subject_id])
            ->all());
    }

    /**
     * Kelas tempat siswa ini terdaftar pada hari ini (zona waktu sekolah); kosong bila bukan siswa aktif.
     *
     * @return list<string>
     */
    public function studentClassIds(): array
    {
        if (! in_array(Role::Student, $this->tenant->roles(), true)) {
            return [];
        }

        return $this->classIds ??= array_values(ClassEnrollment::query()
            ->whereIn('student_id', Student::query()
                ->where('membership_id', $this->tenant->membership()->id)
                ->where('status', Student::STATUS_ACTIVE)
                ->select('id'))
            ->where('start_date', '<=', $this->today())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $this->today()))
            ->pluck('class_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    /** Boleh membuat/mengubah konten dan menilai pada pasangan ini. */
    public function canManage(string $classId, string $subjectId): bool
    {
        return $this->managesAll() || $this->teaches($classId, $subjectId);
    }

    /** Boleh melihat konten (yang diterbitkan, bagi siswa) pada pasangan ini. */
    public function canView(string $classId, string $subjectId): bool
    {
        return $this->viewsAll()
            || $this->teaches($classId, $subjectId)
            || in_array($classId, $this->studentClassIds(), true);
    }

    private function teaches(string $classId, string $subjectId): bool
    {
        foreach ($this->teachingPairs() as $pair) {
            if ($pair['class_id'] === $classId && $pair['subject_id'] === $subjectId) {
                return true;
            }
        }

        return false;
    }

    private function today(): string
    {
        return now($this->tenant->school()->timezone)->toDateString();
    }
}
