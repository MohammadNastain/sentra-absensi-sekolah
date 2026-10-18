<?php

use App\Domain\Identity\Role;
use App\Domain\Scheduling\ScheduleResolver;
use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Tahap 2D — jadwal berversi, hari libur, ScheduleResolver, kalender

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26',
    ]));
    $this->class7a = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => '7A', 'grade_level' => 7,
    ]));
});

function schedApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/{$path}", $data);
}

/** Jadwal standar Senin–Jumat; $overrides menimpa isian. */
function schedBody(array $overrides = []): array
{
    return [
        'scope_type' => 'school', 'days' => [1, 2, 3, 4, 5],
        'check_in_start' => '06:00', 'check_in_deadline' => '07:00', 'late_grace_minutes' => 10,
        'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => '2026-07-13',
        ...$overrides,
    ];
}

function resolveOn(string $date, ?SchoolClass $class = null): array
{
    $t = test();

    return $t->inSchool($t->school, function () use ($t, $date, $class) {
        $day = app(ScheduleResolver::class)->resolve($t->school->id, Carbon::parse($date), $class);

        return [$day->holiday?->name, $day->schedule?->scope_type, $day->schedule ? substr($day->schedule->check_in_deadline, 0, 5) : null];
    });
}

it('menerbitkan jadwal untuk beberapa hari sekaligus dan mencatat audit per baris', function () {
    $response = schedApi('schedules', 'postJson', schedBody())->assertCreated()->assertJsonCount(5, 'data');

    expect(array_column($response->json('data'), 'day_of_week'))->toBe([1, 2, 3, 4, 5]);
    $response->assertJsonPath('data.0.check_in_start', '06:00')->assertJsonPath('data.0.effective_until', null)->assertJsonPath('data.0.is_active', true);

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.schedule.created')->count())->toBe(5);
});

it('versi baru menutup versi lama sehari sebelumnya, dan jam versi lama tidak berubah', function () {
    schedApi('schedules', 'postJson', schedBody(['days' => [1]]))->assertCreated();
    $new = schedApi('schedules', 'postJson', schedBody(['days' => [1], 'check_in_deadline' => '07:30', 'effective_from' => '2026-09-01']))
        ->assertCreated()->json('data.0');

    $all = schedApi('schedules?scope_type=school')->json('data');
    expect($all)->toHaveCount(2)
        ->and($all[0]['effective_from'])->toBe('2026-09-01')->and($all[0]['effective_until'])->toBeNull()
        ->and($all[1]['effective_from'])->toBe('2026-07-13')->and($all[1]['effective_until'])->toBe('2026-08-31')
        ->and($all[1]['check_in_deadline'])->toBe('07:00')
        ->and($new['check_in_deadline'])->toBe('07:30');

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.schedule.updated')->count())->toBe(1);
});

it('menolak versi baru yang tidak lebih baru dari versi terakhir', function () {
    schedApi('schedules', 'postJson', schedBody(['days' => [1], 'effective_from' => '2026-09-01']))->assertCreated();

    schedApi('schedules', 'postJson', schedBody(['days' => [1], 'effective_from' => '2026-09-01']))
        ->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CONFLICT');
    schedApi('schedules', 'postJson', schedBody(['days' => [1], 'effective_from' => '2026-08-01']))->assertStatus(409);
    schedApi('schedules', 'postJson', schedBody(['days' => [2], 'effective_from' => '2026-08-01']))->assertCreated(); // hari lain tidak terpengaruh

    // Konflik di hari ke-3 tidak meninggalkan hari ke-1 dan ke-2 setengah tersimpan.
    $before = count(schedApi('schedules')->json('data'));
    schedApi('schedules', 'postJson', schedBody(['days' => [3, 1], 'effective_from' => '2026-09-01']))->assertStatus(409);
    expect(count(schedApi('schedules')->json('data')))->toBe($before);
});

it('PATCH hanya menutup versi atau menonaktifkannya; jam dan scope tidak bisa diubah', function () {
    $id = schedApi('schedules', 'postJson', schedBody(['days' => [1]]))->json('data.0.id');

    schedApi("schedules/{$id}", 'patchJson', ['effective_until' => '2026-12-18'])->assertOk()->assertJsonPath('data.effective_until', '2026-12-18');
    schedApi("schedules/{$id}", 'patchJson', ['effective_until' => '2026-07-01'])->assertStatus(409)->assertJsonPath('code', 'SCHEDULE_CONFLICT');
    schedApi("schedules/{$id}", 'patchJson', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    schedApi("schedules/{$id}", 'patchJson', ['check_in_deadline' => '08:00'])->assertJsonValidationErrors(['check_in_deadline']);
    schedApi("schedules/{$id}", 'patchJson', ['scope_type' => 'class'])->assertJsonValidationErrors(['scope_type']);
});

it('validasi: urutan jam, scope dan kelengkapannya, hari, format', function () {
    schedApi('schedules', 'postJson', [])->assertJsonValidationErrors(['scope_type', 'days', 'check_in_start', 'effective_from']);
    schedApi('schedules', 'postJson', schedBody(['check_in_deadline' => '05:00']))->assertJsonValidationErrors(['check_in_deadline']);
    schedApi('schedules', 'postJson', schedBody(['check_out_start' => '06:30']))->assertJsonValidationErrors(['check_out_start']);
    schedApi('schedules', 'postJson', schedBody(['scheduled_check_out' => '12:00']))->assertJsonValidationErrors(['scheduled_check_out']);
    schedApi('schedules', 'postJson', schedBody(['days' => [0, 8]]))->assertJsonValidationErrors(['days.0', 'days.1']);
    schedApi('schedules', 'postJson', schedBody(['days' => [1, 1]]))->assertJsonValidationErrors(['days.0']);
    schedApi('schedules', 'postJson', schedBody(['check_in_start' => '6am']))->assertJsonValidationErrors(['check_in_start']);
    schedApi('schedules', 'postJson', schedBody(['late_grace_minutes' => 500]))->assertJsonValidationErrors(['late_grace_minutes']);
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'grade_level']))->assertJsonValidationErrors(['grade_level']);
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'class']))->assertJsonValidationErrors(['class_id']);
    schedApi('schedules', 'postJson', schedBody(['grade_level' => 7]))->assertJsonValidationErrors(['grade_level']);
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'class', 'class_id' => 'bukan-uuid']))->assertJsonValidationErrors(['class_id']);
});

it('ScheduleResolver: prioritas kelas > jenjang > sekolah', function () {
    schedApi('schedules', 'postJson', schedBody(['check_in_deadline' => '07:00']))->assertCreated();
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'grade_level', 'grade_level' => 7, 'check_in_deadline' => '07:10']))->assertCreated();
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'class', 'class_id' => $this->class7a->id, 'check_in_deadline' => '07:20']))->assertCreated();
    $other = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $this->class7a->academic_year_id, 'name' => '7B', 'grade_level' => 7,
    ]));
    $eighth = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $this->class7a->academic_year_id, 'name' => '8A', 'grade_level' => 8,
    ]));

    $monday = '2026-10-19';
    expect(resolveOn($monday, $this->class7a))->toBe([null, 'class', '07:20'])
        ->and(resolveOn($monday, $other))->toBe([null, 'grade_level', '07:10'])
        ->and(resolveOn($monday, $eighth))->toBe([null, 'school', '07:00'])
        ->and(resolveOn($monday))->toBe([null, 'school', '07:00']); // tanpa kelas: hanya jadwal sekolah
});

it('ScheduleResolver: hari pertama dan terakhir berlaku, hari tanpa jadwal, dan versi berurutan', function () {
    schedApi('schedules', 'postJson', schedBody(['days' => [1], 'check_in_deadline' => '07:00', 'effective_from' => '2026-07-13']))->assertCreated();
    schedApi('schedules', 'postJson', schedBody(['days' => [1], 'check_in_deadline' => '07:30', 'effective_from' => '2026-09-14']))->assertCreated();

    expect(resolveOn('2026-07-06'))->toBe([null, null, null])          // Senin sebelum berlaku
        ->and(resolveOn('2026-07-13'))->toBe([null, 'school', '07:00']) // hari pertama versi 1
        ->and(resolveOn('2026-09-07'))->toBe([null, 'school', '07:00']) // Senin terakhir versi 1
        ->and(resolveOn('2026-09-14'))->toBe([null, 'school', '07:30']) // hari pertama versi 2
        ->and(resolveOn('2026-07-14'))->toBe([null, null, null]);       // Selasa: tidak ada jadwal
});

it('ScheduleResolver: libur mengalahkan jadwal, rentang libur inklusif, jadwal nonaktif diabaikan', function () {
    $id = schedApi('schedules', 'postJson', schedBody(['days' => [1, 2, 3]]))->json('data.0.id'); // Senin
    schedApi('holidays', 'postJson', ['name' => 'Libur Semester', 'start_date' => '2026-12-21', 'end_date' => '2026-12-23'])->assertCreated();

    expect(resolveOn('2026-12-20'))->toBe([null, null, null])                 // Minggu
        ->and(resolveOn('2026-12-21'))->toBe(['Libur Semester', null, null])  // Senin libur
        ->and(resolveOn('2026-12-23'))->toBe(['Libur Semester', null, null])  // hari terakhir libur
        ->and(resolveOn('2026-12-24'))->toBe([null, null, null]);             // Kamis tanpa jadwal

    schedApi("schedules/{$id}", 'patchJson', ['is_active' => false])->assertOk();
    expect(resolveOn('2026-10-19'))->toBe([null, null, null]);
});

it('kalender bulanan memuat libur dan jadwal yang berlaku, dengan opsi kelas', function () {
    schedApi('schedules', 'postJson', schedBody(['check_in_deadline' => '07:00']))->assertCreated();
    schedApi('schedules', 'postJson', schedBody(['scope_type' => 'class', 'class_id' => $this->class7a->id, 'days' => [1], 'check_in_deadline' => '07:20']))->assertCreated();
    schedApi('holidays', 'postJson', ['name' => 'Hari Pahlawan', 'start_date' => '2026-11-10', 'end_date' => '2026-11-10'])->assertCreated();

    $days = schedApi('calendar?month=2026-11')->assertOk()->json('data.days');
    expect($days)->toHaveCount(30)
        ->and($days[0]['date'])->toBe('2026-11-01')->and($days[0]['schedule'])->toBeNull()          // Minggu
        ->and($days[1]['date'])->toBe('2026-11-02')->and($days[1]['schedule']['check_in_deadline'])->toBe('07:00')
        ->and($days[9]['holiday']['name'])->toBe('Hari Pahlawan')->and($days[9]['schedule'])->toBeNull();

    $forClass = schedApi("calendar?month=2026-11&class_id={$this->class7a->id}")->json('data.days');
    expect($forClass[1]['schedule']['scope_type'])->toBe('class')->and($forClass[2]['schedule']['scope_type'])->toBe('school');

    schedApi('calendar')->assertJsonValidationErrors(['month']);
    schedApi('calendar?month=2026-13')->assertJsonValidationErrors(['month']);
    schedApi('calendar?month=2026-11&class_id='.fake()->uuid())->assertNotFound();
});

it('hari libur: buat, ubah, hapus dengan audit; validasi rentang; filter', function () {
    $id = schedApi('holidays', 'postJson', ['name' => 'Libur Natal', 'start_date' => '2026-12-24', 'end_date' => '2026-12-26'])
        ->assertCreated()->json('data.id');

    schedApi('holidays', 'postJson', ['name' => 'X', 'start_date' => '2026-12-10', 'end_date' => '2026-12-01'])->assertJsonValidationErrors(['end_date']);
    schedApi('holidays', 'postJson', [])->assertJsonValidationErrors(['name', 'start_date', 'end_date']);
    schedApi("holidays/{$id}", 'patchJson', ['end_date' => '2026-12-20'])->assertJsonValidationErrors(['end_date']); // dibanding start tersimpan
    schedApi("holidays/{$id}", 'patchJson', ['name' => 'Natal', 'end_date' => '2026-12-25'])->assertOk()->assertJsonPath('data.name', 'Natal');

    schedApi('holidays?from=2026-12-25&to=2026-12-31')->assertJsonCount(1, 'data');
    schedApi('holidays?from=2027-01-01')->assertJsonCount(0, 'data');

    schedApi("holidays/{$id}", 'deleteJson')->assertNoContent();
    schedApi("holidays/{$id}")->assertNotFound();

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->whereIn('action', ['school.holiday.created', 'school.holiday.updated', 'school.holiday.deleted'])->count())->toBe(3);
    expect(Holiday::query()->count())->toBe(0);
});

it('T05: jadwal dan libur sekolah lain tidak terlihat atau terubah', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreignHoliday = $this->inSchool($other, fn () => Holiday::query()->create(['school_id' => $other->id, 'name' => 'Asing', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02'])->id);
    $foreignSchedule = $this->inSchool($other, fn () => Schedule::query()->create([
        'school_id' => $other->id, 'scope_type' => 'school', 'day_of_week' => 1, 'check_in_start' => '06:00', 'check_in_deadline' => '07:00',
        'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => '2026-07-13',
    ])->id);

    schedApi("holidays/{$foreignHoliday}")->assertNotFound();
    schedApi("holidays/{$foreignHoliday}", 'deleteJson')->assertNotFound();
    schedApi("schedules/{$foreignSchedule}")->assertNotFound();
    schedApi("schedules/{$foreignSchedule}", 'patchJson', ['is_active' => false])->assertNotFound();
    schedApi('holidays')->assertJsonPath('meta.total', 0);
    schedApi('schedules')->assertJsonPath('meta.total', 0);
    expect(resolveOn('2026-12-01'))->toBe([null, null, null]);
});
