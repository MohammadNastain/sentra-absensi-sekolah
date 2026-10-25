<?php

namespace App\Domain\Lms;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\LmsScope;
use App\Models\LmsAttachment;
use App\Models\LmsMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Materi LMS per kelas-mapel (ADR 0010 §2–§4). Siapa boleh apa diputuskan `LmsScope`; konten yang tidak boleh dilihat
 * dijawab 404 (sama seperti tidak ada), sedangkan konten yang terlihat tetapi tak boleh diubah dijawab 403.
 */
final class ManageMaterials
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly LmsScope $scope,
        private readonly AttachmentStorage $files,
    ) {}

    /**
     * Materi yang boleh dilihat pengguna ini: admin semua; guru kelas-mapelnya (draf hanya miliknya sendiri);
     * siswa dan Kepala Sekolah hanya yang terbit.
     *
     * @return Builder<LmsMaterial>
     */
    public function visible(User $viewer): Builder
    {
        $query = LmsMaterial::query();

        if ($this->scope->managesAll()) {
            return $query;
        }

        return $query->where(function (Builder $any) use ($viewer): void {
            foreach ($this->scope->teachingPairs() as $pair) {
                $any->orWhere(fn (Builder $q) => $q
                    ->where('class_id', $pair['class_id'])->where('subject_id', $pair['subject_id'])
                    ->where(fn (Builder $s) => $s->where('status', LmsMaterial::STATUS_PUBLISHED)->orWhere('author_user_id', $viewer->id)));
            }

            $published = fn (Builder $q) => $q->where('status', LmsMaterial::STATUS_PUBLISHED);

            if ($this->scope->viewsAll()) {
                $any->orWhere($published);
            }
            if (($classIds = $this->scope->studentClassIds()) !== []) {
                $any->orWhere(fn (Builder $q) => $published($q->whereIn('class_id', $classIds)));
            }
            // Tanpa satu pun syarat di atas (mis. guru tanpa jadwal), hasil harus kosong, bukan semua.
            $any->orWhereRaw('false');
        });
    }

    public function find(User $viewer, string $id): LmsMaterial
    {
        return $this->visible($viewer)->find($id) ?? throw new NotFoundHttpException;
    }

    public function canManage(LmsMaterial $material): bool
    {
        return $this->scope->canManage($material->class_id, $material->subject_id);
    }

    public function create(School $school, User $actor, string $classId, string $subjectId, string $title, string $body): LmsMaterial
    {
        if (! $this->scope->canManage($classId, $subjectId)) {
            throw ApiProblem::permissionDenied();
        }

        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $classId, $subjectId, $title, $body) {
            $this->assertPairExists($classId, $subjectId);

            $material = LmsMaterial::query()->create([
                'school_id' => $school->id, 'class_id' => $classId, 'subject_id' => $subjectId, 'author_user_id' => $actor->id,
                'title' => self::clean($title), 'body' => $body, 'status' => LmsMaterial::STATUS_DRAFT,
            ]);
            $this->audit->record('school.lms_material.created', $school->id, 'lms_material', $material->id, null, self::audited($material), null, $actor);

            return $material;
        }));
    }

    /**
     * @param  array{title?: string, body?: string, status?: string}  $changes
     */
    public function update(School $school, User $actor, string $id, array $changes): LmsMaterial
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $changes) {
            $material = $this->find($actor, $id);
            $this->assertCanManage($material);
            $material = LmsMaterial::query()->lockForUpdate()->findOrFail($material->id);
            $before = self::audited($material);

            if (isset($changes['title'])) {
                $material->title = self::clean($changes['title']);
            }
            if (isset($changes['body'])) {
                $material->body = $changes['body'];
            }
            $published = false;
            if (isset($changes['status']) && $changes['status'] !== $material->status) {
                $published = $changes['status'] === LmsMaterial::STATUS_PUBLISHED;
                $material->status = $changes['status'];
                $material->published_at = $published ? now() : null;
            }
            $material->save();

            // Draf tersimpan tidak dicatat (ADR §7); penerbitan dan penarikan dicatat.
            if (isset($changes['status']) && $before['status'] !== $material->status) {
                $this->audit->record($published ? 'school.lms_material.published' : 'school.lms_material.unpublished', $school->id, 'lms_material', $material->id, $before, self::audited($material), null, $actor);
            }

            return $material;
        }));
    }

    public function delete(School $school, User $actor, string $id): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id) {
            $material = $this->find($actor, $id);
            $this->assertCanManage($material);

            // Berkas fisik dihapus setelah transaksi berhasil; baris lampiran ikut terhapus oleh trigger.
            $keys = array_values(array_map('strval', LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_MATERIAL)->where('owner_id', $material->id)->pluck('storage_key')->all()));
            $material->delete();
            $this->audit->record('school.lms_material.deleted', $school->id, 'lms_material', $material->id, self::audited($material), null, null, $actor);
            DB::afterCommit(fn () => $this->files->forget($keys));
        }));
    }

    public function assertCanManage(LmsMaterial $material): void
    {
        if (! $this->canManage($material)) {
            throw ApiProblem::permissionDenied();
        }
    }

    private function assertPairExists(string $classId, string $subjectId): void
    {
        $errors = [];
        if (! SchoolClass::query()->whereKey($classId)->exists()) {
            $errors['class_id'] = ['Kelas tidak ditemukan.'];
        }
        if (! Subject::query()->whereKey($subjectId)->exists()) {
            $errors['subject_id'] = ['Mata pelajaran tidak ditemukan.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(LmsMaterial $material): array
    {
        return ['class_id' => $material->class_id, 'subject_id' => $material->subject_id, 'title' => $material->title, 'status' => $material->status];
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
