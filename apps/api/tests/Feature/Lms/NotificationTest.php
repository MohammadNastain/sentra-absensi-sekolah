<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Support\Carbon;

// ADR 0010 §8 — notifikasi dalam aplikasi: tugas terbit, dinilai, diminta revisi; hanya untuk penerimanya.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 08:00:00');
    config(['sentra.lms_released' => true]);

    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    foreach (['lms', 'lms.materials', 'lms.attachments', 'lms.assignments', 'lms.submissions', 'lms.grading'] as $feature) {
        setFeatureOverride($this->school->id, $feature, 'grant');
    }

    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $class = fn (string $name) => $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7, 'status' => 'active',
    ])->id);
    $this->class7a = $class('7A');
    $this->class7b = $class('7B');
    $this->math = $this->inSchool($this->school, fn () => Subject::query()->create(['school_id' => $this->school->id, 'name' => 'Matematika'])->id);

    $this->teacher = User::factory()->create();
    $membership = $this->addMember($this->school, $this->teacher, [Role::Teacher]);
    $teacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Bu Guru', 'membership_id' => $membership->id, 'status' => 'active',
    ])->id);
    $this->inSchool($this->school, fn () => TeachingSlot::query()->create([
        'school_id' => $this->school->id, 'class_id' => $this->class7a, 'subject_id' => $this->math, 'teacher_id' => $teacherId,
        'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
    ]));

    $student = function (string $number, string $class) {
        $user = User::factory()->create();
        $membership = $this->addMember($this->school, $user, [Role::Student]);
        $id = $this->inSchool($this->school, function () use ($number, $class, $membership) {
            $row = Student::query()->create([
                'school_id' => $this->school->id, 'full_name' => 'Siswa '.$number, 'student_number' => $number, 'status' => 'active', 'membership_id' => $membership->id,
            ]);
            ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $row->id, 'class_id' => $class, 'start_date' => '2020-01-01']);

            return $row->id;
        });

        return [$user, $id];
    };
    [$this->student] = $student('1', $this->class7a);
    [$this->student2] = $student('2', $this->class7a);
    [$this->studentB] = $student('3', $this->class7b);
    // Siswa tanpa akun tidak punya penerima notifikasi, dan tidak boleh membuat penerbitan gagal.
    $this->inSchool($this->school, function () {
        $row = Student::query()->create(['school_id' => $this->school->id, 'full_name' => 'Tanpa Akun', 'student_number' => '9', 'status' => 'active']);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $row->id, 'class_id' => $this->class7a, 'start_date' => '2020-01-01']);
    });

    $this->url = '/api/v1/schools/sekolah-a/lms';
    $this->publish = function (string $title = 'PR 1') {
        $id = $this->actingAs($this->teacher)->postJson("{$this->url}/assignments", [
            'class_id' => $this->class7a, 'subject_id' => $this->math, 'title' => $title, 'due_at' => '2026-11-02 08:00:00', 'max_score' => 20,
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'published'])->assertOk();

        return $id;
    };
    $this->notes = fn (User $by) => $this->flushSession()->actingAs($by)->getJson("{$this->url}/notifications");
});

afterEach(fn () => Carbon::setTestNow());

it('menerbitkan tugas memberi tahu siswa di kelas itu saja, sekali, bukan guru atau kelas lain', function () {
    $id = ($this->publish)();

    foreach ([$this->student, $this->student2] as $user) {
        ($this->notes)($user)->assertOk()->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.type', 'lms.assignment_published')
            ->assertJsonPath('data.items.0.title', 'Tugas baru: PR 1')
            ->assertJsonPath('data.items.0.assignment_id', $id)
            ->assertJsonPath('data.items.0.read', false);
    }
    ($this->notes)($this->studentB)->assertJsonPath('data.unread_count', 0);
    ($this->notes)($this->teacher)->assertJsonPath('data.unread_count', 0);

    // Menutup lalu membuka kembali tidak mengulang pemberitahuan.
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'closed'])->assertOk();
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'published'])->assertOk();
    ($this->notes)($this->student)->assertJsonPath('data.unread_count', 1);
});

it('menyimpan draf tidak memberi tahu siapa pun', function () {
    $this->actingAs($this->teacher)->postJson("{$this->url}/assignments", [
        'class_id' => $this->class7a, 'subject_id' => $this->math, 'title' => 'Draf',
    ])->assertCreated();

    ($this->notes)($this->student)->assertJsonPath('data.unread_count', 0);
});

it('menilai dan meminta revisi memberi tahu pemilik pengumpulan tanpa membocorkan komentar guru', function () {
    $id = ($this->publish)();
    $submission = $this->flushSession()->actingAs($this->student)->putJson("{$this->url}/assignments/{$id}/submission", ['body' => 'Jawaban'])->assertOk()->json('data.id');
    $this->flushSession()->actingAs($this->student2)->putJson("{$this->url}/assignments/{$id}/submission", ['body' => 'Jawaban 2'])->assertOk();

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$submission}/return", ['feedback' => 'Rahasia komentar guru'])->assertOk();
    $returned = ($this->notes)($this->student)->assertJsonPath('data.unread_count', 2)->assertJsonPath('data.items.0.type', 'lms.submission_returned')->json('data.items.0');
    expect($returned['title'])->toBe('Perlu revisi: PR 1')->and(json_encode($returned))->not->toContain('Rahasia');

    $this->flushSession()->actingAs($this->student)->putJson("{$this->url}/assignments/{$id}/submission", ['body' => 'Revisi'])->assertOk();
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$submission}/grade", ['score' => 17.5, 'feedback' => 'Bagus'])->assertOk();
    ($this->notes)($this->student)->assertJsonPath('data.items.0.type', 'lms.submission_graded')
        ->assertJsonPath('data.items.0.body', 'Nilai Anda 17.5 dari 20.');

    // Siswa lain hanya menerima pemberitahuan terbit, bukan nilai teman.
    ($this->notes)($this->student2)->assertJsonPath('data.unread_count', 1);
});

it('penerima menandai satu atau semua notifikasinya sudah dibaca tanpa memengaruhi orang lain', function () {
    ($this->publish)('PR 1');
    ($this->publish)('PR 2');
    $items = ($this->notes)($this->student)->assertJsonPath('data.unread_count', 2)->json('data.items');

    $this->flushSession()->actingAs($this->student)->postJson("{$this->url}/notifications/read", ['id' => $items[0]['id']])->assertOk()->assertJsonPath('data.unread_count', 1);
    // Id milik orang lain diabaikan (bukan error, bukan efek).
    $this->flushSession()->actingAs($this->student2)->postJson("{$this->url}/notifications/read", ['id' => $items[1]['id']])->assertOk()->assertJsonPath('data.unread_count', 2);
    ($this->notes)($this->student)->assertJsonPath('data.unread_count', 1);

    $this->flushSession()->actingAs($this->student)->postJson("{$this->url}/notifications/read")->assertOk()->assertJsonPath('data.unread_count', 0);
    ($this->notes)($this->student2)->assertJsonPath('data.unread_count', 2);
});

it('notifikasi satu sekolah tidak terlihat di sekolah lain', function () {
    ($this->publish)();
    $other = School::factory()->create(['code' => 'sekolah-b']);
    setFeatureOverride($other->id, 'lms', 'grant');
    $this->addMember($other, $this->student, [Role::Teacher]);

    $this->flushSession()->actingAs($this->student)->getJson('/api/v1/schools/sekolah-b/lms/notifications')->assertOk()->assertJsonPath('data.unread_count', 0);
});
