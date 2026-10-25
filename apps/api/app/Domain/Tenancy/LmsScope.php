<?php

namespace App\Domain\Tenancy;

use App\Domain\Identity\Role;
use App\Models\ClassEnrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use Illuminate\Database\Eloquent\Builder;

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

    /** Keanggotaan yang dipakai mengisi cache; bila berganti (instance bertahan antar request), cache dibuang. */
    private ?string $cachedFor = null;

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

        $this->resetCacheIfMembershipChanged();

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

        $this->resetCacheIfMembershipChanged();

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

    /**
     * Membatasi query konten (materi/tugas, berkolom class_id, subject_id, status, author_user_id) ke yang boleh dilihat:
     * admin semua; guru kelas-mapelnya (status selain terlihat-umum hanya miliknya); siswa dan Kepala Sekolah hanya
     * status di $visibleStatuses (siswa hanya kelasnya). Tanpa hak apa pun hasilnya kosong, bukan semua.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $visibleStatuses
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, string $viewerId, array $visibleStatuses): Builder
    {
        if ($this->managesAll()) {
            return $query;
        }

        return $query->where(function (Builder $any) use ($viewerId, $visibleStatuses): void {
            foreach ($this->teachingPairs() as $pair) {
                $any->orWhere(fn (Builder $q) => $q
                    ->where('class_id', $pair['class_id'])->where('subject_id', $pair['subject_id'])
                    ->where(fn (Builder $s) => $s->whereIn('status', $visibleStatuses)->orWhere('author_user_id', $viewerId)));
            }
            if ($this->viewsAll()) {
                $any->orWhereIn('status', $visibleStatuses);
            }
            if (($classIds = $this->studentClassIds()) !== []) {
                $any->orWhere(fn (Builder $q) => $q->whereIn('class_id', $classIds)->whereIn('status', $visibleStatuses));
            }
            $any->orWhereRaw('false');
        });
    }

    public function schoolTimezone(): string
    {
        return $this->tenant->school()->timezone;
    }

    /** Id siswa aktif yang terhubung ke akun ini (null bila bukan siswa). */
    public function studentId(): ?string
    {
        if (! in_array(Role::Student, $this->tenant->roles(), true)) {
            return null;
        }

        $id = Student::query()->where('membership_id', $this->tenant->membership()->id)->where('status', Student::STATUS_ACTIVE)->value('id');

        return is_string($id) ? $id : null;
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

    private function resetCacheIfMembershipChanged(): void
    {
        $current = $this->tenant->membership()->id;

        if ($this->cachedFor !== $current) {
            $this->pairs = $this->classIds = null;
            $this->cachedFor = $current;
        }
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
