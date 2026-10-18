<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Tahap 2D — constraint dan RLS tabel jadwal/libur, diuji langsung di DB sebagai sentra_app.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->ctx = $this->dbContext();
});

function expectScheduleViolation(string $schoolId, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($schoolId, fn () => DB::transaction($operation)))
        ->toThrow(QueryException::class, $constraint);
}

function insertScheduleRow(string $school, array $extra = []): string
{
    return insertRow('schedules', [
        'school_id' => $school, 'scope_type' => 'school', 'day_of_week' => 1,
        'check_in_start' => '06:00', 'check_in_deadline' => '07:00', 'check_out_start' => '13:00', 'scheduled_check_out' => '14:00',
        'effective_from' => '2026-07-13', ...$extra,
    ]);
}

it('jadwal: versi aktif satu scope dan hari tidak boleh tumpang tindih; hari lain, scope lain, atau nonaktif boleh', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        insertScheduleRow($this->schoolA, ['effective_until' => '2026-08-31']);
        insertScheduleRow($this->schoolA, ['effective_from' => '2026-09-01']);               // berurutan: boleh
        insertScheduleRow($this->schoolA, ['day_of_week' => 2]);                              // hari lain
        insertScheduleRow($this->schoolA, ['scope_type' => 'grade_level', 'grade_level' => 7]); // scope lain
        insertScheduleRow($this->schoolA, ['is_active' => 'false']);                          // nonaktif tidak dihitung
    });
    $this->ctx->runInSchool($this->schoolB, fn () => insertScheduleRow($this->schoolB)); // sekolah lain bebas

    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['effective_from' => '2026-08-31']), 'schedules_no_overlap');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['scope_type' => 'grade_level', 'grade_level' => 7, 'effective_from' => '2027-01-01']), 'schedules_no_overlap');
});

it('jadwal: konsistensi scope, urutan jam, hari, toleransi, dan rentang berlaku', function () {
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['scope_type' => 'grade_level']), 'schedules_scope_consistency');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['grade_level' => 7]), 'schedules_scope_consistency');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['scope_type' => 'class']), 'schedules_scope_consistency');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['check_in_deadline' => '05:00']), 'schedules_time_order');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['check_out_start' => '06:30']), 'schedules_time_order');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['scheduled_check_out' => '12:00']), 'schedules_time_order');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['day_of_week' => 8]), 'schedules_day_check');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['late_grace_minutes' => 121]), 'schedules_grace_check');
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['effective_until' => '2026-07-01']), 'schedules_effective_check');
});

it('jadwal: FK komposit mencegah kelas sekolah lain, dan libur harus berurutan', function () {
    $yearB = $this->ctx->runInSchool($this->schoolB, fn () => insertRow('academic_years', ['school_id' => $this->schoolB, 'name' => 'Uji', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26']));
    $classB = $this->ctx->runInSchool($this->schoolB, fn () => insertRow('classes', ['school_id' => $this->schoolB, 'academic_year_id' => $yearB, 'name' => '7A', 'grade_level' => 7]));

    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolA, ['scope_type' => 'class', 'class_id' => $classB]), 'schedules_class_fk');
    expectScheduleViolation($this->schoolA, fn () => insertRow('holidays', ['school_id' => $this->schoolA, 'name' => 'Terbalik', 'start_date' => '2026-12-02', 'end_date' => '2026-12-01']), 'holidays_dates_check');
    expectScheduleViolation($this->schoolA, fn () => insertRow('holidays', ['school_id' => $this->schoolA, 'name' => ' ', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01']), 'holidays_name_not_blank');
});

it('scope_key dihitung database dan RLS memisahkan sekolah', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        insertScheduleRow($this->schoolA);
        insertScheduleRow($this->schoolA, ['scope_type' => 'grade_level', 'grade_level' => 7]);
        insertRow('holidays', ['school_id' => $this->schoolA, 'name' => 'Libur', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02']);
    });
    $this->ctx->runInSchool($this->schoolB, fn () => insertScheduleRow($this->schoolB));

    expect(DB::table('schedules')->count())->toBe(0);
    expect($this->ctx->runInSchool($this->schoolA, fn () => DB::table('schedules')->orderBy('scope_key')->pluck('scope_key')->all()))->toBe(['grade:7', 'school']);
    expect($this->ctx->runInSchool($this->schoolB, fn () => DB::table('holidays')->count()))->toBe(0);
    expectScheduleViolation($this->schoolA, fn () => insertScheduleRow($this->schoolB), 'row-level security');
});
