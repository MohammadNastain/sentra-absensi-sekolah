<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Jadwal mengajar — mata pelajaran dan jadwal mengajar (guru x kelas x mapel x hari x jam)

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->inSchool($this->school, function () {
        $year = AcademicYear::query()->create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26', 'is_active' => true]);
        $class = fn (string $name) => SchoolClass::query()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7])->id;
        $this->class7a = $class('7A');
        $this->class7b = $class('7B');
        $teacher = fn (string $name) => Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => $name, 'status' => 'active'])->id;
        $this->siti = $teacher('Siti Rahma');
        $this->budi = $teacher('Budi Santoso');
        $subject = fn (string $name) => Subject::query()->create(['school_id' => $this->school->id, 'name' => $name])->id;
        $this->mtk = $subject('Matematika');
        $this->ipa = $subject('IPA');
    });
});

function slotsApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/teaching-slots{$path}", $data);
}

function slotPayload(array $override = []): array
{
    $t = test();

    return [
        'class_id' => $t->class7a, 'subject_id' => $t->mtk, 'teacher_id' => $t->siti,
        'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:20', ...$override,
    ];
}

it('membuat jam mengajar, menampilkannya urut hari dan jam, dan mencatat audit', function () {
    slotsApi('', 'postJson', slotPayload(['day_of_week' => 2]))->assertCreated()
        ->assertJsonPath('data.day_of_week', 2)
        ->assertJsonPath('data.start_time', '07:00')
        ->assertJsonPath('data.class.name', '7A')
        ->assertJsonPath('data.subject.name', 'Matematika')
        ->assertJsonPath('data.teacher.full_name', 'Siti Rahma');
    slotsApi('', 'postJson', slotPayload(['start_time' => '08:20', 'end_time' => '09:40', 'subject_id' => $this->ipa]))->assertCreated();

    slotsApi()->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.day_of_week', 1)
        ->assertJsonPath('data.1.day_of_week', 2);
    slotsApi("?class_id={$this->class7b}")->assertJsonCount(0, 'data');
    slotsApi("?teacher_id={$this->siti}&day_of_week=2")->assertJsonCount(1, 'data');
    expect($this->inSchool($this->school, fn () => DB::table('audit_logs')->where('action', 'school.teaching_slot.created')->count()))->toBe(2);
});

it('guru atau kelas yang sama tidak boleh punya dua pelajaran pada jam beririsan, tetapi slot berurutan boleh', function () {
    slotsApi('', 'postJson', slotPayload())->assertCreated();

    // Guru yang sama, kelas lain, jam beririsan.
    slotsApi('', 'postJson', slotPayload(['class_id' => $this->class7b, 'start_time' => '07:30', 'end_time' => '08:00']))
        ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CONFLICT');
    // Kelas yang sama, guru lain, jam beririsan.
    slotsApi('', 'postJson', slotPayload(['teacher_id' => $this->budi, 'start_time' => '06:00', 'end_time' => '07:01']))
        ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CONFLICT');
    // Berurutan (selesai 08.20, mulai 08.20) dan hari lain boleh.
    slotsApi('', 'postJson', slotPayload(['start_time' => '08:20', 'end_time' => '09:40']))->assertCreated();
    slotsApi('', 'postJson', slotPayload(['day_of_week' => 3]))->assertCreated();
    expect($this->inSchool($this->school, fn () => TeachingSlot::query()->count()))->toBe(3);
});

it('menolak input tidak valid: jam terbalik, hari di luar rentang, kelas diarsipkan, guru nonaktif', function () {
    slotsApi('', 'postJson', slotPayload(['end_time' => '06:00']))->assertUnprocessable()->assertJsonValidationErrors('end_time');
    slotsApi('', 'postJson', slotPayload(['day_of_week' => 8]))->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
    slotsApi('', 'postJson', slotPayload(['start_time' => '7 pagi']))->assertUnprocessable()->assertJsonValidationErrors('start_time');

    $this->inSchool($this->school, function () {
        SchoolClass::query()->whereKey($this->class7b)->update(['status' => 'archived']);
        Teacher::query()->whereKey($this->budi)->update(['status' => 'inactive']);
    });
    slotsApi('', 'postJson', slotPayload(['class_id' => $this->class7b]))->assertUnprocessable()->assertJsonValidationErrors('class_id');
    slotsApi('', 'postJson', slotPayload(['teacher_id' => $this->budi]))->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
});

it('mengubah dan menghapus jam mengajar; ubah jam diperiksa ulang terhadap bentrok dan urutan', function () {
    $first = slotsApi('', 'postJson', slotPayload())->json('data.id');
    $second = slotsApi('', 'postJson', slotPayload(['start_time' => '08:20', 'end_time' => '09:40']))->json('data.id');

    slotsApi("/{$second}", 'patchJson', ['start_time' => '08:00'])->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CONFLICT');
    slotsApi("/{$second}", 'patchJson', ['end_time' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors('end_time');
    slotsApi("/{$second}", 'patchJson', ['subject_id' => $this->ipa, 'end_time' => '10:00'])->assertOk()
        ->assertJsonPath('data.subject.name', 'IPA')->assertJsonPath('data.end_time', '10:00');
    expect($this->inSchool($this->school, fn () => DB::table('audit_logs')->where('action', 'school.teaching_slot.updated')->count()))->toBe(1);

    slotsApi("/{$first}", 'deleteJson')->assertNoContent();
    slotsApi()->assertJsonCount(1, 'data');
    expect($this->inSchool($this->school, fn () => DB::table('audit_logs')->where('action', 'school.teaching_slot.deleted')->count()))->toBe(1);
});

it('mapel: tambah, nama kembar (beda huruf besar) ditolak, ganti nama, hapus hanya bila tidak dipakai', function () {
    $api = fn (string $path = '', string $method = 'getJson', array $data = []) => test()->actingAs(test()->admin)->{$method}("/api/v1/schools/sekolah-a/subjects{$path}", $data);

    $id = $api('', 'postJson', ['name' => '  Bahasa   Indonesia '])->assertCreated()->assertJsonPath('data.name', 'Bahasa Indonesia')->json('data.id');
    $api('', 'postJson', ['name' => 'bahasa indonesia'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_VALUE');
    $api('', 'postJson', ['name' => '   '])->assertUnprocessable();

    $api("/{$id}", 'patchJson', ['name' => 'B. Indonesia'])->assertOk()->assertJsonPath('data.name', 'B. Indonesia');

    slotsApi('', 'postJson', slotPayload())->assertCreated();
    $api()->assertOk()->assertJsonPath('data.2.name', 'Matematika')->assertJsonPath('data.2.slot_count', 1);
    $api("/{$this->mtk}", 'deleteJson')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $api("/{$id}", 'deleteJson')->assertNoContent();
});

it('guru yang masih punya jadwal mengajar tidak bisa dinonaktifkan; mapel guru bisa diatur', function () {
    slotsApi('', 'postJson', slotPayload())->assertCreated();
    $teachers = fn (string $path, string $method, array $data = []) => test()->actingAs(test()->admin)->{$method}("/api/v1/schools/sekolah-a/teachers{$path}", $data);

    $teachers("/{$this->siti}", 'patchJson', ['status' => 'inactive'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $teachers("/{$this->siti}", 'patchJson', ['email' => 'siti@sekolah.test', 'subject_ids' => [$this->ipa, $this->mtk]])->assertOk()
        ->assertJsonPath('data.email', 'siti@sekolah.test')
        ->assertJsonPath('data.subjects.0.name', 'IPA')
        ->assertJsonPath('data.subjects.1.name', 'Matematika');
    $teachers("/{$this->siti}", 'patchJson', ['subject_ids' => []])->assertOk()->assertJsonCount(0, 'data.subjects');
    $teachers("/{$this->siti}", 'patchJson', ['email' => 'bukan-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('T05: jadwal dan mapel sekolah lain tidak terlihat dan tidak bisa dipakai', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreignSubject = $this->inSchool($other, fn () => Subject::query()->create(['school_id' => $other->id, 'name' => 'Asing'])->id);

    slotsApi('', 'postJson', slotPayload(['subject_id' => $foreignSubject]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-b/teaching-slots')->assertNotFound();
});

it('wali kelas hanya melihat jadwal kelas yang diampunya', function () {
    $homeroom = User::factory()->create();
    $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);
    $membershipId = $this->inSchool($this->school, fn () => DB::table('school_memberships')->where('user_id', $homeroom->id)->value('id'));
    $this->inSchool($this->school, function () use ($membershipId) {
        Teacher::query()->whereKey($this->budi)->update(['membership_id' => $membershipId]);
        SchoolClass::query()->whereKey($this->class7a)->update(['homeroom_teacher_id' => $this->budi]);
    });
    slotsApi('', 'postJson', slotPayload())->assertCreated();
    slotsApi('', 'postJson', slotPayload(['class_id' => $this->class7b, 'teacher_id' => $this->budi, 'day_of_week' => 2]))->assertCreated();
    slotsApi('', 'postJson', slotPayload(['class_id' => $this->class7b, 'day_of_week' => 3]))->assertCreated();

    $this->actingAs($homeroom)->getJson('/api/v1/schools/sekolah-a/teaching-slots')->assertOk()
        ->assertJsonCount(2, 'data'); // kelas 7A (diampu) + jam yang ia ajar sendiri di 7B; jam Siti di 7B tidak terlihat
});
