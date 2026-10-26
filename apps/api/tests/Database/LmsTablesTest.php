<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// ADR 0010 §3, §8 — constraint dan RLS tabel LMS, diuji langsung di DB sebagai sentra_app (lapis pertahanan di bawah aplikasi).

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->ctx = $this->dbContext();
    $this->user = createUserRow('guru@example.test');
});

function lmsViolation(string $schoolId, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($schoolId, fn () => DB::transaction($operation)))
        ->toThrow(QueryException::class, $constraint);
}

/** @return array{class: string, subject: string, student: string} */
function lmsFixtures(string $school): array
{
    $year = insertRow('academic_years', ['school_id' => $school, 'name' => 'Uji', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26']);

    return [
        'class' => insertRow('classes', ['school_id' => $school, 'academic_year_id' => $year, 'name' => '7A', 'grade_level' => 7]),
        'subject' => insertRow('subjects', ['school_id' => $school, 'name' => 'Matematika']),
        'student' => insertRow('students', ['school_id' => $school, 'student_number' => '1001', 'full_name' => 'Siswa Satu']),
    ];
}

function lmsAssignmentRow(string $school, string $user, array $fx, array $extra = []): string
{
    return insertRow('lms_assignments', ['school_id' => $school, 'class_id' => $fx['class'], 'subject_id' => $fx['subject'], 'author_user_id' => $user, 'title' => 'Tugas', ...$extra]);
}

it('materi: judul tidak kosong, status terbatas, terbit berpasangan dengan waktu terbit, kelas dan mapel harus sesekolah', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        $fx = lmsFixtures($this->schoolA);
        $base = ['school_id' => $this->schoolA, 'class_id' => $fx['class'], 'subject_id' => $fx['subject'], 'author_user_id' => $this->user];

        insertRow('lms_materials', [...$base, 'title' => 'Bab 1']);
        $this->fx = $fx;
    });
    $fx = $this->fx;
    $base = ['school_id' => $this->schoolA, 'class_id' => $fx['class'], 'subject_id' => $fx['subject'], 'author_user_id' => $this->user];

    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'title' => '  ']), 'lms_materials_title_not_blank');
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'title' => 'X', 'status' => 'arsip']), 'lms_materials_status_check');
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'title' => 'X', 'status' => 'published']), 'lms_materials_published_at_check');
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'title' => 'X', 'published_at' => now()]), 'lms_materials_published_at_check');

    // Kelas milik sekolah lain tidak bisa ditautkan walau baris sekolah A sendiri sah.
    $other = $this->ctx->runInSchool($this->schoolB, fn () => lmsFixtures($this->schoolB));
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'class_id' => $other['class'], 'title' => 'X']), 'lms_materials_class_fk');
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [...$base, 'subject_id' => $other['subject'], 'title' => 'X']), 'lms_materials_subject_fk');
});

it('tugas: nilai maksimum 0 < n <= 10000, status terbatas, status draf berpasangan dengan waktu terbit', function () {
    $fx = $this->ctx->runInSchool($this->schoolA, fn () => lmsFixtures($this->schoolA));
    $this->ctx->runInSchool($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx));

    lmsViolation($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx, ['max_score' => 0]), 'lms_assignments_max_score_check');
    lmsViolation($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx, ['max_score' => 10001]), 'lms_assignments_max_score_check');
    lmsViolation($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx, ['status' => 'dibuka', 'published_at' => now()]), 'lms_assignments_status_check');
    lmsViolation($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx, ['status' => 'closed']), 'lms_assignments_published_at_check');
    lmsViolation($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx, ['title' => '']), 'lms_assignments_title_not_blank');
});

it('pengumpulan: satu per siswa per tugas; dinilai wajib bernilai, penilai, dan waktu; revisi wajib berkomentar; siswa sesekolah', function () {
    $fx = $this->ctx->runInSchool($this->schoolA, fn () => lmsFixtures($this->schoolA));
    $assignment = $this->ctx->runInSchool($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx));
    $row = fn (array $extra = []) => ['school_id' => $this->schoolA, 'assignment_id' => $assignment, 'student_id' => $fx['student'], 'submitted_at' => now(), ...$extra];

    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('lms_submissions', $row()));

    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row()), 'lms_submissions_one_per_student');

    $fx2 = $this->ctx->runInSchool($this->schoolA, fn () => insertRow('students', ['school_id' => $this->schoolA, 'student_number' => '1002', 'full_name' => 'Siswa Dua']));
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'status' => 'graded'])), 'lms_submissions_graded_has_score');
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'status' => 'graded', 'score' => 80])), 'lms_submissions_graded_has_score');
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'status' => 'returned_for_revision'])), 'lms_submissions_returned_has_feedback');
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'status' => 'returned_for_revision', 'feedback' => '  '])), 'lms_submissions_returned_has_feedback');
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'score' => -1])), 'lms_submissions_score_check');
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $fx2, 'status' => 'dihapus'])), 'lms_submissions_status_check');

    // Siswa sekolah B tidak bisa mengumpulkan ke tugas sekolah A, bahkan dari konteks A.
    $foreign = $this->ctx->runInSchool($this->schoolB, fn () => lmsFixtures($this->schoolB)['student']);
    lmsViolation($this->schoolA, fn () => insertRow('lms_submissions', $row(['student_id' => $foreign])), 'lms_submissions_student_fk');
});

it('lampiran: pemilik harus ada di sekolah yang sama; jenis pemilik, ukuran, dan kunci simpan dijaga', function () {
    $fx = $this->ctx->runInSchool($this->schoolA, fn () => lmsFixtures($this->schoolA));
    $assignment = $this->ctx->runInSchool($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx));
    $foreignAssignment = $this->ctx->runInSchool($this->schoolB, function () {
        $fx = lmsFixtures($this->schoolB);

        return lmsAssignmentRow($this->schoolB, $this->user, $fx);
    });
    $row = fn (array $extra = []) => [
        'school_id' => $this->schoolA, 'owner_type' => 'assignment', 'owner_id' => $assignment, 'original_name' => 'soal.pdf',
        'storage_key' => $this->schoolA.'/'.fake()->uuid(), 'mime' => 'application/pdf', 'size' => 1024, 'sha256' => str_repeat('a', 64),
        'uploaded_by_user_id' => $this->user, ...$extra,
    ];

    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('lms_attachments', $row()));

    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['owner_id' => $foreignAssignment])), 'Pemilik lampiran tidak ditemukan');
    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['owner_id' => fake()->uuid()])), 'Pemilik lampiran tidak ditemukan');
    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['owner_type' => 'kelas'])), 'Pemilik lampiran tidak ditemukan');
    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['size' => 10485761])), 'lms_attachments_size_check');
    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['size' => 0])), 'lms_attachments_size_check');

    $key = $this->schoolA.'/duplikat';
    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('lms_attachments', $row(['storage_key' => $key])));
    lmsViolation($this->schoolA, fn () => insertRow('lms_attachments', $row(['storage_key' => $key])), 'lms_attachments_storage_key_unique');
});

it('RLS: tanpa konteks tidak ada baris LMS terlihat, dan konteks A tidak melihat atau mengubah baris B', function () {
    $fxB = $this->ctx->runInSchool($this->schoolB, fn () => lmsFixtures($this->schoolB));
    $assignmentB = $this->ctx->runInSchool($this->schoolB, fn () => lmsAssignmentRow($this->schoolB, $this->user, $fxB));
    $this->ctx->runInSchool($this->schoolB, fn () => insertRow('lms_materials', [
        'school_id' => $this->schoolB, 'class_id' => $fxB['class'], 'subject_id' => $fxB['subject'], 'author_user_id' => $this->user, 'title' => 'Materi B',
    ]));
    $this->ctx->runInSchool($this->schoolB, fn () => insertRow('lms_submissions', [
        'school_id' => $this->schoolB, 'assignment_id' => $assignmentB, 'student_id' => $fxB['student'], 'submitted_at' => now(),
    ]));
    $this->ctx->runInSchool($this->schoolB, fn () => insertRow('lms_attachments', [
        'school_id' => $this->schoolB, 'owner_type' => 'assignment', 'owner_id' => $assignmentB, 'original_name' => 'b.pdf',
        'storage_key' => $this->schoolB.'/b', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('b', 64), 'uploaded_by_user_id' => $this->user,
    ]));

    $tables = ['lms_materials', 'lms_assignments', 'lms_submissions', 'lms_attachments'];
    $this->ctx->reset();
    foreach ($tables as $table) {
        expect(DB::scalar("SELECT count(*) FROM {$table}"))->toBe(0, "{$table} terlihat tanpa konteks");
    }

    $this->ctx->runInSchool($this->schoolA, function () use ($tables, $assignmentB) {
        foreach ($tables as $table) {
            expect(DB::scalar("SELECT count(*) FROM {$table}"))->toBe(0, "{$table} B terlihat dari konteks A");
        }
        expect(DB::update('UPDATE lms_assignments SET title = ? WHERE id = ?', ['dibajak', $assignmentB]))->toBe(0)
            ->and(DB::delete('DELETE FROM lms_assignments WHERE id = ?', [$assignmentB]))->toBe(0);
    });

    // Menulis baris ber-school_id sekolah B dari konteks A ditolak oleh WITH CHECK.
    lmsViolation($this->schoolA, fn () => insertRow('lms_materials', [
        'school_id' => $this->schoolB, 'class_id' => $fxB['class'], 'subject_id' => $fxB['subject'], 'author_user_id' => $this->user, 'title' => 'Bajakan',
    ]), 'row-level security');

    expect($this->ctx->runInSchool($this->schoolB, fn () => DB::scalar('SELECT title FROM lms_assignments WHERE id = ?', [$assignmentB])))->toBe('Tugas');
});

it('menghapus tugas menghapus lampirannya lewat pemicu, dan tugas berpengumpulan tidak bisa dihapus', function () {
    $fx = $this->ctx->runInSchool($this->schoolA, fn () => lmsFixtures($this->schoolA));
    $assignment = $this->ctx->runInSchool($this->schoolA, fn () => lmsAssignmentRow($this->schoolA, $this->user, $fx));
    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('lms_attachments', [
        'school_id' => $this->schoolA, 'owner_type' => 'assignment', 'owner_id' => $assignment, 'original_name' => 'a.pdf',
        'storage_key' => $this->schoolA.'/a', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('c', 64), 'uploaded_by_user_id' => $this->user,
    ]));
    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('lms_submissions', [
        'school_id' => $this->schoolA, 'assignment_id' => $assignment, 'student_id' => $fx['student'], 'submitted_at' => now(),
    ]));

    lmsViolation($this->schoolA, fn () => DB::delete('DELETE FROM lms_assignments WHERE id = ?', [$assignment]), 'lms_submissions_assignment_fk');

    $this->ctx->runInSchool($this->schoolA, fn () => DB::delete('DELETE FROM lms_submissions WHERE assignment_id = ?', [$assignment]));
    $this->ctx->runInSchool($this->schoolA, fn () => DB::delete('DELETE FROM lms_assignments WHERE id = ?', [$assignment]));

    expect($this->ctx->runInSchool($this->schoolA, fn () => DB::scalar('SELECT count(*) FROM lms_attachments')))->toBe(0);
});
