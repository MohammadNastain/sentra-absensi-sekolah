<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Identity\StudentLogin;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Akun login siswa dari data siswa (ADR 0010 §1): dibuat Admin Sekolah dari NIS tanpa email, dengan kata sandi awal
 * acak yang dikembalikan SEKALI ke pemanggil. Kata sandi tidak disimpan terbaca dan tidak masuk audit; siswa wajib
 * menggantinya saat login pertama (`users.must_change_password`).
 */
final class ManageStudentAccounts
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Membuat akun untuk siswa-siswa ini. Yang tidak memenuhi syarat dilewati dengan alasan, bukan menggagalkan semuanya.
     *
     * @param  list<string>  $studentIds
     * @return array{created: list<array<string, string>>, skipped: list<array{student_id: string, reason: string}>}
     */
    public function create(School $school, User $actor, array $studentIds): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentIds) {
            $created = [];
            $skipped = [];
            $students = Student::query()->where('school_id', $school->id)->whereIn('id', $studentIds)->orderBy('student_number')->get()->keyBy('id');

            foreach ($studentIds as $id) {
                $student = $students->get($id);
                $reason = $student === null ? 'not_found' : $this->ineligibleReason($school, $student);
                if ($reason !== null || $student === null) {
                    $skipped[] = ['student_id' => $id, 'reason' => $reason ?? 'not_found'];

                    continue;
                }

                $password = StudentLogin::generatePassword();
                $email = StudentLogin::syntheticEmail($school->code, $student->student_number);

                $user = new User;
                $user->forceFill([
                    'email' => $email,
                    'name' => $student->full_name,
                    'password' => $password,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'must_change_password' => true,
                ])->save();

                $membership = SchoolMembership::query()->create([
                    'school_id' => $school->id,
                    'user_id' => $user->id,
                    'status' => SchoolMembership::STATUS_ACTIVE,
                ]);
                DB::table('membership_roles')->insert([
                    'school_id' => $school->id,
                    'membership_id' => $membership->id,
                    'role_code' => Role::Student->value,
                    'granted_by_user_id' => $actor->id,
                ]);
                $student->forceFill(['membership_id' => $membership->id])->save();

                $username = $school->code.'/'.$student->student_number;
                $this->audit->record('school.student_account.created', $school->id, 'student_account', $student->id, after: [
                    'username' => $username, 'membership_id' => $membership->id,
                ], actor: $actor);

                $created[] = $this->credentials($student, $username, $password);
            }

            return ['created' => $created, 'skipped' => $skipped];
        }));
    }

    /**
     * Atur ulang kata sandi: sandi awal baru dan wajib ganti lagi saat login berikutnya.
     *
     * @return array<string, string>
     */
    public function resetPassword(School $school, User $actor, string $studentId): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentId) {
            $student = Student::query()->where('school_id', $school->id)->lockForUpdate()->find($studentId)
                ?? throw new NotFoundHttpException;
            $membership = $student->membership_id === null ? null : SchoolMembership::query()->with('user')->find($student->membership_id);
            if ($membership === null) {
                throw ApiProblem::invalidStateTransition('Siswa ini belum memiliki akun.');
            }

            $password = StudentLogin::generatePassword();
            $membership->user->forceFill(['password' => $password, 'must_change_password' => true])->save();

            $username = $school->code.'/'.$student->student_number;
            $this->audit->record('school.student_account.password_reset', $school->id, 'student_account', $student->id, after: [
                'username' => $username, 'membership_id' => $membership->id,
            ], actor: $actor);

            return $this->credentials($student, $username, $password);
        }));
    }

    private function ineligibleReason(School $school, Student $student): ?string
    {
        return match (true) {
            $student->membership_id !== null => 'has_account',
            $student->status !== Student::STATUS_ACTIVE => 'inactive',
            ! StudentLogin::isValidNis($student->student_number) => 'invalid_number',
            User::query()->where('email', StudentLogin::syntheticEmail($school->code, $student->student_number))->exists() => 'conflict',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function credentials(Student $student, string $username, string $password): array
    {
        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'full_name' => $student->full_name,
            'username' => $username,
            'initial_password' => $password,
        ];
    }
}
