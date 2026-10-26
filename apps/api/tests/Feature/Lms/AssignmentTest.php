<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\LmsAttachment;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// ADR 0010 §4, §7, §8 — tugas, pengumpulan (waktu server), penilaian, revisi, dan lampiran pengumpulan.

function workPdf(string $name = 'jawaban.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'lms');
    file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 08:00:00');
    config(['sentra.lms_released' => true]);
    Storage::fake('lms');

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
    $subject = fn (string $name) => $this->inSchool($this->school, fn () => Subject::query()->create(['school_id' => $this->school->id, 'name' => $name])->id);
    $this->class7a = $class('7A');
    $this->class7b = $class('7B');
    $this->math = $subject('Matematika');
    $this->art = $subject('Seni');

    $teacher = function (string $name, string $class, string $subject) {
        $user = User::factory()->create();
        $membership = $this->addMember($this->school, $user, [Role::Teacher]);
        $teacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
            'school_id' => $this->school->id, 'full_name' => $name, 'membership_id' => $membership->id, 'status' => 'active',
        ])->id);
        $this->inSchool($this->school, fn () => TeachingSlot::query()->create([
            'school_id' => $this->school->id, 'class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $teacherId,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]));

        return $user;
    };
    $this->teacher = $teacher('Bu Guru', $this->class7a, $this->math);
    $this->artTeacher = $teacher('Bu Seni', $this->class7b, $this->art);

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
    [$this->student, $this->studentId] = $student('1', $this->class7a);
    [$this->student2, $this->student2Id] = $student('2', $this->class7a);
    [$this->studentB] = $student('3', $this->class7b);
    // Siswa tanpa akun: tetap harus muncul di daftar penilai sebagai "belum mengumpulkan".
    $this->inSchool($this->school, function () {
        $row = Student::query()->create(['school_id' => $this->school->id, 'full_name' => 'Siswa Tanpa Akun', 'student_number' => '9', 'status' => 'active']);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $row->id, 'class_id' => $this->class7a, 'start_date' => '2020-01-01']);
    });

    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->principal = User::factory()->create();
    $this->addMember($this->school, $this->principal, [Role::Principal]);

    $this->url = '/api/v1/schools/sekolah-a/lms';
    // Tugas terbit dengan tenggat besok pukul 08.00, nilai maksimal 20, tidak menerima terlambat.
    $this->makeAssignment = function (array $over = [], bool $publish = true) {
        $id = $this->actingAs($this->teacher)->postJson("{$this->url}/assignments", array_merge([
            'class_id' => $this->class7a, 'subject_id' => $this->math, 'title' => 'PR 1', 'instructions' => 'Kerjakan hal. 10',
            'due_at' => '2026-11-02 08:00:00', 'max_score' => 20,
        ], $over))->assertCreated()->json('data.id');
        if ($publish) {
            $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'published'])->assertOk();
        }

        return $id;
    };
    // Sesi dashboard kedaluwarsa 12 jam sejak login; tes yang memajukan jam memulai sesi baru tiap kirim.
    $this->submit = fn (User $by, string $assignment, string $body = 'Jawaban saya') => $this->flushSession()->actingAs($by)->putJson("{$this->url}/assignments/{$assignment}/submission", ['body' => $body]);
    $this->upload = fn (User $by, string $path, UploadedFile $f) => $this->actingAs($by)->post("{$this->url}/{$path}", ['file' => $f], ['Accept' => 'application/json']);
});

afterEach(fn () => Carbon::setTestNow());

it('guru membuat tugas hanya untuk kelas-mapelnya; admin untuk mana saja; siswa dan kepala sekolah tidak', function () {
    $create = fn (User $by, array $over = []) => $this->actingAs($by)->postJson("{$this->url}/assignments", array_merge([
        'class_id' => $this->class7a, 'subject_id' => $this->math, 'title' => 'PR',
    ], $over));

    $create($this->teacher)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.max_score', 100)->assertJsonPath('data.accept_late', false);
    $create($this->teacher, ['subject_id' => $this->art])->assertForbidden();
    $create($this->artTeacher)->assertForbidden();
    $create($this->admin, ['class_id' => $this->class7b, 'subject_id' => $this->art])->assertCreated();
    $create($this->student)->assertForbidden();
    $create($this->principal)->assertForbidden();
    $create($this->teacher, ['max_score' => 0])->assertUnprocessable()->assertJsonValidationErrors('max_score');
    $create($this->teacher, ['title' => ' '])->assertUnprocessable();
});

it('draf tugas tak terlihat siswa; terbit dan tertutup terlihat siswa kelasnya saja', function () {
    $id = ($this->makeAssignment)([], false);

    foreach ([$this->student, $this->principal, $this->artTeacher, $this->studentB] as $viewer) {
        $this->actingAs($viewer)->getJson("{$this->url}/assignments/{$id}")->assertNotFound();
    }
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'published'])->assertOk()->assertJsonPath('data.published_at', fn ($v) => $v !== null);

    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertOk()->assertJsonPath('data.my_submission', null);
    $this->actingAs($this->principal)->getJson("{$this->url}/assignments/{$id}")->assertOk();
    foreach ([$this->studentB, $this->artTeacher] as $outsider) {
        $this->actingAs($outsider)->getJson("{$this->url}/assignments/{$id}")->assertNotFound();
        expect($this->actingAs($outsider)->getJson("{$this->url}/assignments")->json('data'))->toBe([]);
    }

    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'closed'])->assertOk();
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertOk()->assertJsonPath('data.status', 'closed');
});

it('mengumpulkan sebelum tenggat: tepat waktu, bisa dikirim ulang; waktu dari server', function () {
    $id = ($this->makeAssignment)();

    ($this->submit)($this->student, $id, 'Versi 1')->assertOk()
        ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.is_late', false)->assertJsonPath('data.submitted_at', Carbon::now()->toIso8601String());

    Carbon::setTestNow('2026-11-02 07:59:59');
    ($this->submit)($this->student, $id, 'Versi 2')->assertOk()->assertJsonPath('data.body', 'Versi 2')->assertJsonPath('data.is_late', false);
    expect($this->inSchool($this->school, fn () => LmsSubmission::query()->count()))->toBe(1);

    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertJsonPath('data.my_submission.status', 'submitted');
    // Klien tidak bisa mengirim waktu sendiri.
    $this->actingAs($this->student)->putJson("{$this->url}/assignments/{$id}/submission", ['body' => 'x', 'submitted_at' => '2020-01-01', 'is_late' => true])->assertOk()->assertJsonPath('data.is_late', false);
});

it('lewat tenggat: ditolak bila tidak menerima terlambat; ditandai terlambat bila menerima', function () {
    $strict = ($this->makeAssignment)();
    $lenient = ($this->makeAssignment)(['title' => 'PR 2', 'accept_late' => true]);
    $noDue = ($this->makeAssignment)(['title' => 'PR 3', 'due_at' => null]);

    Carbon::setTestNow('2026-11-02 08:00:01');
    ($this->submit)($this->student, $strict)->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    ($this->submit)($this->student, $lenient)->assertOk()->assertJsonPath('data.is_late', true);
    ($this->submit)($this->student, $noDue)->assertOk()->assertJsonPath('data.is_late', false);

    // Mengubah tenggat tidak mengubah is_late pengumpulan yang sudah ada.
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$lenient}", ['due_at' => '2027-01-01 00:00:00'])->assertOk();
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$lenient}/submission")->assertJsonPath('data.is_late', true);
});

it('tugas draf/tertutup, siswa kelas lain, guru, dan kepala sekolah tidak dapat mengumpulkan', function () {
    $draft = ($this->makeAssignment)([], false);
    $id = ($this->makeAssignment)();

    ($this->submit)($this->student, $draft)->assertNotFound();
    ($this->submit)($this->studentB, $id)->assertNotFound();
    ($this->submit)($this->teacher, $id)->assertForbidden();
    ($this->submit)($this->principal, $id)->assertForbidden();

    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'closed'])->assertOk();
    ($this->submit)($this->student, $id)->assertConflict();
});

it('penilaian: nilai dalam batas, siswa baru melihat nilai setelah dinilai, hasil dinilai terkunci', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 21])->assertUnprocessable()->assertJsonValidationErrors('score');
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => -1])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", [])->assertUnprocessable();

    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}/submission")->assertJsonPath('data.score', null)->assertJsonPath('data.feedback', null);

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 17.5, 'feedback' => 'Bagus'])
        ->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 17.5);

    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}/submission")
        ->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 17.5)->assertJsonPath('data.feedback', 'Bagus');

    ($this->submit)($this->student, $id, 'Ubah diam-diam')->assertConflict();
    $this->actingAs($this->admin)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 18])->assertOk()->assertJsonPath('data.score', 18);
});

it('hanya penilai kelas-mapelnya yang dapat menilai; siswa, kepala sekolah, dan guru lain tidak', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');

    $this->actingAs($this->student)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 20])->assertForbidden();
    $this->actingAs($this->principal)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 20])->assertForbidden();
    $this->actingAs($this->artTeacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 20])->assertNotFound();
    $this->actingAs($this->artTeacher)->getJson("{$this->url}/submissions/{$sid}")->assertNotFound();
    $this->actingAs($this->artTeacher)->getJson("{$this->url}/assignments/{$id}/submissions")->assertNotFound();
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}/submissions")->assertForbidden();
    expect($this->inSchool($this->school, fn () => LmsSubmission::query()->findOrFail($sid)->status))->toBe('submitted');
});

it('minta revisi: komentar wajib; siswa mengirim ulang walau tenggat lewat; keterlambatan tidak bertambah', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/return", [])->assertUnprocessable()->assertJsonValidationErrors('feedback');
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/return", ['feedback' => 'Lengkapi no. 3'])
        ->assertOk()->assertJsonPath('data.status', 'returned_for_revision');
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 10])->assertConflict();

    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}/submission")->assertJsonPath('data.feedback', 'Lengkapi no. 3')->assertJsonPath('data.score', null);

    Carbon::setTestNow('2026-12-01 08:00:00'); // jauh lewat tenggat
    ($this->submit)($this->student, $id, 'Sudah direvisi')->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.is_late', false);

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 19])->assertOk();
});

it('daftar penilai memuat seluruh siswa kelas, termasuk yang belum mengumpulkan atau belum punya akun', function () {
    $id = ($this->makeAssignment)();
    ($this->submit)($this->student, $id)->assertOk();

    $rows = $this->actingAs($this->teacher)->getJson("{$this->url}/assignments/{$id}/submissions")->assertOk()->json('data');
    $byName = collect($rows)->keyBy('student.full_name');

    expect($rows)->toHaveCount(3)
        ->and($byName['Siswa 1']['submission']['status'])->toBe('submitted')
        ->and($byName['Siswa 2']['submission'])->toBeNull()
        ->and($byName['Siswa Tanpa Akun']['submission'])->toBeNull()
        ->and($byName->keys()->all())->not->toContain('Siswa 3');
});

it('lampiran pengumpulan: siswa mengunggah dan menghapus miliknya; penilai mengunduh; yang lain tidak', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');

    ($this->upload)($this->student2, "submissions/{$sid}/attachments", workPdf())->assertNotFound();
    ($this->upload)($this->teacher, "submissions/{$sid}/attachments", workPdf())->assertForbidden();
    $attId = ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf('Jawaban PR.pdf'))->assertCreated()->json('data.id');

    $row = $this->inSchool($this->school, fn () => LmsAttachment::query()->findOrFail($attId));
    expect($row->owner_type)->toBe('submission')->and($row->storage_key)->toStartWith($this->school->id.'/');

    $download = "{$this->url}/attachments/{$attId}/download";
    foreach ([$this->student, $this->teacher, $this->admin] as $reader) {
        $this->actingAs($reader)->get($download)->assertOk();
    }
    // Pekerjaan siswa lain, siswa kelas lain, guru mapel lain, dan kepala sekolah tidak berhak membacanya.
    foreach ([$this->student2, $this->studentB, $this->artTeacher, $this->principal] as $other) {
        $this->actingAs($other)->get($download)->assertNotFound();
    }
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}/submission")->assertJsonPath('data.attachments.0.name', 'Jawaban PR.pdf');

    $this->actingAs($this->teacher)->deleteJson("{$this->url}/attachments/{$attId}")->assertNotFound();
    $this->actingAs($this->student2)->deleteJson("{$this->url}/submissions/{$sid}/attachments/{$attId}")->assertNotFound();
    $this->actingAs($this->student)->deleteJson("{$this->url}/submissions/{$sid}/attachments/{$attId}")->assertNoContent();
    Storage::disk('lms')->assertMissing($row->storage_key);
});

it('lampiran pengumpulan terkunci setelah dinilai atau tugas ditutup', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $attId = ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf())->json('data.id');

    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 15])->assertOk();
    ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf())->assertConflict();
    $this->actingAs($this->student)->deleteJson("{$this->url}/submissions/{$sid}/attachments/{$attId}")->assertConflict();
    $this->actingAs($this->student)->get("{$this->url}/attachments/{$attId}/download")->assertOk();
});

it('lampiran tugas dikelola guru dan terbaca siswa hanya setelah terbit', function () {
    $id = ($this->makeAssignment)([], false);
    $attId = ($this->upload)($this->teacher, "assignments/{$id}/attachments", workPdf('soal.pdf'))->assertCreated()->json('data.id');
    ($this->upload)($this->student, "assignments/{$id}/attachments", workPdf())->assertForbidden();
    ($this->upload)($this->artTeacher, "assignments/{$id}/attachments", workPdf())->assertNotFound();

    $this->actingAs($this->student)->get("{$this->url}/attachments/{$attId}/download")->assertNotFound();
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['status' => 'published'])->assertOk();
    $this->actingAs($this->student)->get("{$this->url}/attachments/{$attId}/download")->assertOk();
    $this->actingAs($this->studentB)->get("{$this->url}/attachments/{$attId}/download")->assertNotFound();

    $this->actingAs($this->teacher)->deleteJson("{$this->url}/attachments/{$attId}")->assertNoContent();
});

it('menghapus tugas: ditolak bila sudah ada pengumpulan; bila kosong, lampiran ikut terhapus', function () {
    $withWork = ($this->makeAssignment)();
    ($this->submit)($this->student, $withWork)->assertOk();
    $this->actingAs($this->teacher)->deleteJson("{$this->url}/assignments/{$withWork}")->assertConflict();
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$withWork}", ['status' => 'draft'])->assertConflict();

    $empty = ($this->makeAssignment)(['title' => 'Kosong'], false);
    ($this->upload)($this->teacher, "assignments/{$empty}/attachments", workPdf())->assertCreated();
    expect(Storage::disk('lms')->allFiles())->toHaveCount(1);
    $this->actingAs($this->teacher)->deleteJson("{$this->url}/assignments/{$empty}")->assertNoContent();
    expect(Storage::disk('lms')->allFiles())->toBe([]);
    $this->inSchool($this->school, fn () => expect(LmsAttachment::query()->count())->toBe(0));
});

it('menurunkan nilai maksimum di bawah nilai yang sudah diberikan ditolak', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 18])->assertOk();

    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['max_score' => 10])->assertUnprocessable()->assertJsonValidationErrors('max_score');
    $this->actingAs($this->teacher)->patchJson("{$this->url}/assignments/{$id}", ['max_score' => 25])->assertOk();
});

it('mencabut fitur menutup rutenya: pengumpulan, penilaian, dan lampiran berdiri sendiri-sendiri', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $attId = ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf())->json('data.id');

    setFeatureOverride($this->school->id, 'lms.grading', 'revoke');
    $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 10])->assertForbidden()->assertJsonPath('feature', 'lms.grading');
    $this->actingAs($this->teacher)->getJson("{$this->url}/assignments/{$id}/submissions")->assertOk();

    setFeatureOverride($this->school->id, 'lms.attachments', 'revoke');
    $this->actingAs($this->student)->get("{$this->url}/attachments/{$attId}/download")->assertForbidden()->assertJsonPath('feature', 'lms.attachments');
    ($this->submit)($this->student, $id, 'Masih bisa')->assertOk();

    setFeatureOverride($this->school->id, 'lms.submissions', 'revoke');
    ($this->submit)($this->student, $id)->assertForbidden()->assertJsonPath('feature', 'lms.submissions');
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertOk();

    setFeatureOverride($this->school->id, 'lms.assignments', 'revoke');
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertForbidden()->assertJsonPath('feature', 'lms.assignments');
});

it('audit penilaian mencatat pelaku dan status, tanpa nilai maupun komentar', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $this->actingAs($this->admin)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => 12.5, 'feedback' => 'Rahasia komentar'])->assertOk();

    $this->inSchool($this->school, function () use ($sid) {
        $row = DB::table('audit_logs')->where('action', 'school.lms_submission.graded')->first();
        expect($row)->not->toBeNull()
            ->and($row->entity_id)->toBe($sid)
            ->and($row->actor_user_id)->toBe($this->admin->id)
            ->and($row->before_value.$row->after_value)->not->toContain('12.5')->not->toContain('Rahasia')->not->toContain('score')->not->toContain('feedback');
        expect(json_decode($row->after_value, true)['status'])->toBe('graded');
    });
});

it('lintas sekolah: admin sekolah lain tidak melihat, mengumpulkan, menilai, atau mengunduh', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    foreach (['lms', 'lms.materials', 'lms.attachments', 'lms.assignments', 'lms.submissions', 'lms.grading'] as $feature) {
        setFeatureOverride($other->id, $feature, 'grant');
    }
    $outsider = User::factory()->create();
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);

    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $attId = ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf())->json('data.id');

    $b = '/api/v1/schools/sekolah-b/lms';
    $this->actingAs($outsider)->getJson("{$b}/assignments/{$id}")->assertNotFound();
    $this->actingAs($outsider)->patchJson("{$b}/assignments/{$id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($outsider)->getJson("{$b}/assignments/{$id}/submissions")->assertNotFound();
    $this->actingAs($outsider)->getJson("{$b}/submissions/{$sid}")->assertNotFound();
    $this->actingAs($outsider)->postJson("{$b}/submissions/{$sid}/grade", ['score' => 1])->assertNotFound();
    $this->actingAs($outsider)->get("{$b}/attachments/{$attId}/download")->assertNotFound();
});

it('siswa dengan sandi awal belum boleh memakai LMS sebelum menggantinya', function () {
    $id = ($this->makeAssignment)();
    $sid = ($this->submit)($this->student, $id)->json('data.id');
    $attId = ($this->upload)($this->student, "submissions/{$sid}/attachments", workPdf())->json('data.id');

    $this->student->forceFill(['must_change_password' => true])->save();

    foreach (['materials', 'assignments', "assignments/{$id}", "assignments/{$id}/submission", "attachments/{$attId}/download"] as $path) {
        $this->actingAs($this->student)->getJson("{$this->url}/{$path}")->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }
    ($this->submit)($this->student, $id, 'diam-diam')->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

    $this->student->forceFill(['must_change_password' => false])->save();
    $this->actingAs($this->student)->getJson("{$this->url}/assignments/{$id}")->assertOk();
});

it('batas masukan: jawaban dan instruksi terlalu panjang, nilai di luar rentang, dan HTML disimpan sebagai teks', function () {
    $id = ($this->makeAssignment)();

    ($this->submit)($this->student, $id, str_repeat('a', 20001))->assertUnprocessable();
    $html = '<script>alert(1)</script>';
    ($this->submit)($this->student, $id, $html)->assertOk()->assertJsonPath('data.body', $html);

    $sid = $this->actingAs($this->teacher)->getJson("{$this->url}/assignments/{$id}/submissions")->json('data.0.submission.id');
    foreach ([-1, 21, 'abc'] as $bad) {
        $this->actingAs($this->teacher)->postJson("{$this->url}/submissions/{$sid}/grade", ['score' => $bad])->assertUnprocessable();
    }
});
