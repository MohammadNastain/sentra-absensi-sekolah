<?php

namespace App\Domain\Credentials\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Credentials\CredentialHasher;
use App\Domain\Credentials\QrToken;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentCredential;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kredensial QR siswa. Satu QR aktif per siswa (D-17): menerbitkan yang baru mencabut semua QR lama siswa itu
 * dalam transaksi yang sama (D-16, kartu lama otomatis tidak berlaku). Token mentah hanya dikembalikan sekali
 * kepada pemanggil dan tidak pernah disimpan, dicatat di audit, maupun di log. Status hanya maju:
 * active ⇄ blocked → revoked.
 */
final class ManageCredentials
{
    public const MAX_CLASS_BATCH = 200;

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly CredentialHasher $hasher,
    ) {}

    /**
     * @return array{credential: StudentCredential, token: string}
     */
    public function issueQr(School $school, User $actor, string $studentId): array
    {
        return $this->run($school, function () use ($school, $actor, $studentId) {
            $student = $this->lockStudent($school, $studentId);
            if ($student->status !== Student::STATUS_ACTIVE) {
                throw ApiProblem::invalidStateTransition('Kartu hanya dapat diterbitkan untuk siswa aktif.');
            }

            return $this->issueFor($school, $actor, $student);
        });
    }

    /**
     * Menerbitkan QR untuk siswa dengan penempatan berjalan di kelas. mode `missing`: hanya yang belum punya QR
     * aktif; `all`: terbit ulang semuanya.
     *
     * @return list<array{student: Student, credential: StudentCredential, token: string}>
     */
    public function issueForClass(School $school, User $actor, string $classId, string $mode): array
    {
        return $this->run($school, function () use ($school, $actor, $classId, $mode) {
            SchoolClass::query()->where('school_id', $school->id)->find($classId) ?? throw new NotFoundHttpException;
            $today = Carbon::now($school->timezone)->toDateString();

            $students = Student::query()
                ->where('school_id', $school->id)
                ->where('status', Student::STATUS_ACTIVE)
                ->whereIn('id', ClassEnrollment::query()
                    ->where('school_id', $school->id)
                    ->where('class_id', $classId)
                    ->where('start_date', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                    ->select('student_id'))
                ->orderBy('full_name')->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($students->count() > self::MAX_CLASS_BATCH) {
                throw ApiProblem::invalidStateTransition('Kelas terlalu besar untuk diterbitkan sekaligus (maksimum '.self::MAX_CLASS_BATCH.' siswa).');
            }

            $withActive = $mode === 'all' ? [] : StudentCredential::query()
                ->where('school_id', $school->id)
                ->where('credential_type', StudentCredential::TYPE_QR)
                ->where('status', StudentCredential::STATUS_ACTIVE)
                ->whereIn('student_id', $students->pluck('id'))
                ->pluck('student_id')->all();

            $issued = [];
            foreach ($students as $student) {
                if (in_array($student->id, $withActive, true)) {
                    continue;
                }
                $issued[] = ['student' => $student] + $this->issueFor($school, $actor, $student);
            }

            return $issued;
        });
    }

    public function block(School $school, User $actor, string $credentialId, string $reason): StudentCredential
    {
        return $this->transition($school, $actor, $credentialId, [StudentCredential::STATUS_ACTIVE], StudentCredential::STATUS_BLOCKED, 'school.credential.blocked', $reason);
    }

    public function unblock(School $school, User $actor, string $credentialId, ?string $reason): StudentCredential
    {
        return $this->transition($school, $actor, $credentialId, [StudentCredential::STATUS_BLOCKED], StudentCredential::STATUS_ACTIVE, 'school.credential.unblocked', $reason);
    }

    public function revoke(School $school, User $actor, string $credentialId, string $reason): StudentCredential
    {
        return $this->transition($school, $actor, $credentialId, [StudentCredential::STATUS_ACTIVE, StudentCredential::STATUS_BLOCKED], StudentCredential::STATUS_REVOKED, 'school.credential.revoked', $reason);
    }

    /**
     * @return array{credential: StudentCredential, token: string}
     */
    private function issueFor(School $school, User $actor, Student $student): array
    {
        $previous = StudentCredential::query()
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('credential_type', StudentCredential::TYPE_QR)
            ->where('status', '<>', StudentCredential::STATUS_REVOKED)
            ->orderByDesc('issued_at')
            ->lockForUpdate()
            ->get();

        foreach ($previous as $old) {
            $before = $this->snapshot($old);
            $old->forceFill([
                'status' => StudentCredential::STATUS_REVOKED,
                'status_reason' => 'Diganti kartu baru',
                'revoked_at' => now(),
            ])->save();
            $this->audit->record('school.credential.revoked', $school->id, 'student_credential', $old->id, $before, $this->snapshot($old), 'Diganti kartu baru', $actor);
        }

        $token = QrToken::generate();
        $version = $this->hasher->currentVersion();
        $hex = $this->hasher->hashHex(QrToken::normalize($token), $version);
        // Hash dikirim sebagai hex terikat parameter lalu didekode di SQL (kolom bytea).
        $row = DB::selectOne(
            "INSERT INTO student_credentials (school_id, student_id, credential_type, value_hash, hash_key_version, status, replaces_credential_id, created_by_user_id)
             VALUES (?, ?, ?, decode(?, 'hex'), ?, ?, ?, ?) RETURNING id",
            [$school->id, $student->id, StudentCredential::TYPE_QR, $hex, $version, StudentCredential::STATUS_ACTIVE, $previous->first()?->id, $actor->id],
        );
        $credentialId = (string) ($row->id ?? '');
        $credential = StudentCredential::query()->findOrFail($credentialId);

        $this->audit->record('school.credential.issued', $school->id, 'student_credential', $credential->id, null, $this->snapshot($credential), null, $actor);

        return ['credential' => $credential, 'token' => $token];
    }

    /**
     * @param  list<string>  $allowedFrom
     */
    private function transition(School $school, User $actor, string $credentialId, array $allowedFrom, string $to, string $action, ?string $reason): StudentCredential
    {
        return $this->run($school, function () use ($school, $actor, $credentialId, $allowedFrom, $to, $action, $reason) {
            $credential = StudentCredential::query()->where('school_id', $school->id)->lockForUpdate()->find($credentialId)
                ?? throw new NotFoundHttpException;
            if (! in_array($credential->status, $allowedFrom, true)) {
                throw ApiProblem::invalidStateTransition("Kredensial berstatus '{$credential->status}' tidak dapat diubah menjadi '{$to}'.");
            }
            if ($to === StudentCredential::STATUS_ACTIVE) {
                $another = StudentCredential::query()->where('school_id', $school->id)->where('student_id', $credential->student_id)
                    ->where('credential_type', $credential->credential_type)->where('status', StudentCredential::STATUS_ACTIVE)->exists();
                if ($another) {
                    throw ApiProblem::invalidStateTransition('Siswa sudah memiliki kredensial aktif lain.');
                }
            }

            $before = $this->snapshot($credential);
            $credential->forceFill([
                'status' => $to,
                'status_reason' => $reason,
                'blocked_at' => $to === StudentCredential::STATUS_BLOCKED ? now() : ($to === StudentCredential::STATUS_ACTIVE ? null : $credential->blocked_at),
                'revoked_at' => $to === StudentCredential::STATUS_REVOKED ? now() : null,
            ])->save();

            $this->audit->record($action, $school->id, 'student_credential', $credential->id, $before, $this->snapshot($credential), $reason, $actor);

            return $credential;
        });
    }

    private function lockStudent(School $school, string $studentId): Student
    {
        return Student::query()->where('school_id', $school->id)->lockForUpdate()->find($studentId) ?? throw new NotFoundHttpException;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function run(School $school, callable $work): mixed
    {
        try {
            return $this->database->runInSchool($school->id, fn () => DB::transaction(fn () => $work()));
        } catch (QueryException $e) {
            throw ConstraintViolations::translate($e) ?? $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StudentCredential $credential): array
    {
        return [
            'student_id' => $credential->student_id,
            'credential_type' => $credential->credential_type,
            'status' => $credential->status,
            'status_reason' => $credential->status_reason,
            'replaces_credential_id' => $credential->replaces_credential_id,
        ];
    }
}
