<?php

use App\Domain\Attendance\CloseAttendanceDay;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 5A — penutupan hari, aturan alpa sementara, penahanan (ADR 0005 §5)

beforeEach(function () {
    scanSetUp()->call($this);
    $this->absent = scanStudent($this, 'Budi Santoso', '1002');
    DB::table('schools')->where('id', $this->school->id)->update(['absence_rule_enabled' => true, 'absence_cutoff_time' => '09:00']);
});

afterEach(fn () => Carbon::setTestNow());

function closeDay(string $date = '2026-10-19'): array
{
    $t = test();

    return $t->inSchool($t->school, fn () => app(CloseAttendanceDay::class)->run($t->school, $date));
}

function dailyRows(): array
{
    $t = test();

    return $t->inSchool($t->school, fn () => DB::table('daily_attendance')->orderBy('student_id')->get()->keyBy('student_id')->all());
}

function atTime(string $time, string $date = '2026-10-19'): void
{
    Carbon::setTestNow(Carbon::parse("{$date} {$time}", 'Asia/Jakarta'));
}

it('sebelum cutoff: baris harian dibuat belum_tercatat dengan snapshot kelas, tanpa alpa', function () {
    atTime('08:00');
    $result = closeDay();

    expect($result)->toMatchArray(['required' => 2, 'marked_absent' => 0, 'health' => null]);
    $rows = dailyRows();
    expect($rows)->toHaveCount(2)
        ->and($rows[$this->absent]->attendance_status)->toBe('belum_tercatat')
        ->and($rows[$this->absent]->class_id)->toBe($this->classId)
        ->and($rows[$this->absent]->attendance_session_id)->not->toBeNull();
});

it('setelah cutoff pada hari sehat: tanpa catatan menjadi alpa_belum_diverifikasi, yang hadir tetap; idempoten', function () {
    atTime('06:30');
    scan($this->token)->assertJsonPath('data.result', 'accepted');
    atTime('09:30');

    expect(closeDay())->toMatchArray(['required' => 2, 'marked_absent' => 1, 'health' => 'ok']);
    $first = dailyRows();
    expect($first[$this->absent]->attendance_status)->toBe('alpa_belum_diverifikasi')
        ->and($first[$this->absent]->absence_basis)->toBe('rule')
        ->and($first[$this->absent]->verified_at)->toBeNull()
        ->and($first[$this->student]->attendance_status)->toBe('hadir');

    expect(closeDay())->toMatchArray(['marked_absent' => 0, 'health' => 'ok']);
    expect(dailyRows())->toEqual($first);
});

it('aturan mati: tidak pernah menandai alpa', function () {
    DB::table('schools')->where('id', $this->school->id)->update(['absence_rule_enabled' => false]);
    atTime('06:30');
    scan($this->token);
    atTime('09:30');

    expect(closeDay())->toMatchArray(['marked_absent' => 0, 'health' => null]);
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('belum_tercatat');
});

it('nol scan padahal ada siswa wajib hadir: aturan ditahan dan alasan tercatat', function () {
    atTime('09:30');

    expect(closeDay())->toMatchArray(['marked_absent' => 0, 'health' => 'perlu_pemeriksaan']);
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('belum_tercatat');
    $health = $this->inSchool($this->school, fn () => DB::table('attendance_day_health')->first());
    expect($health->status)->toBe('perlu_pemeriksaan')->and(json_decode($health->reasons, true))->toBe(['NO_SCANS']);
});

it('hari libur, tanpa jadwal, dan siswa nonaktif tidak pernah dibuatkan baris atau alpa', function () {
    $inactive = scanStudent($this, 'Nonaktif', '1003');
    $this->inSchool($this->school, fn () => Student::query()->whereKey($inactive)->update(['status' => 'inactive']));

    atTime('09:30', '2026-10-24'); // Sabtu: tanpa jadwal
    expect(closeDay('2026-10-24'))->toMatchArray(['required' => 0, 'marked_absent' => 0]);
    expect(dailyRows())->toBe([]);

    atTime('06:30');
    scan($this->token);
    atTime('09:30');
    closeDay();
    expect(dailyRows())->not->toHaveKey($inactive);
});

it('tanggal lampau selalu melewati cutoff', function () {
    atTime('06:30');
    scan($this->token);
    atTime('05:00', '2026-10-20');

    expect(closeDay('2026-10-19'))->toMatchArray(['marked_absent' => 1, 'health' => 'ok']);
});

it('status izin/sakit/dispensasi tidak ditimpa aturan alpa', function () {
    atTime('06:30');
    scan($this->token);
    atTime('09:30');
    closeDay();
    $this->inSchool($this->school, fn () => DB::table('daily_attendance')->where('student_id', $this->absent)
        ->update(['attendance_status' => 'sakit', 'absence_basis' => null]));

    expect(closeDay())->toMatchArray(['marked_absent' => 0]);
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('sakit');
});

it('database menolak alpa tanpa verifikator atau tanpa dasar', function () {
    atTime('08:00');
    closeDay();

    $this->inSchool($this->school, function () {
        expect(fn () => DB::transaction(fn () => DB::table('daily_attendance')->where('student_id', $this->absent)
            ->update(['attendance_status' => 'alpa', 'absence_basis' => 'manual'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('daily_attendance')->where('student_id', $this->absent)
            ->update(['attendance_status' => 'alpa_belum_diverifikasi'])))->toThrow(QueryException::class);
    });
});

it('penandaan gangguan menarik kembali alpa otomatis dan menahan aturan; pelepasan menjalankannya lagi', function () {
    atTime('06:30');
    scan($this->token);
    atTime('09:30');
    closeDay();
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('alpa_belum_diverifikasi');

    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->markDisruption($this->school, '2026-10-19', $this->admin, 'Terminal gerbang mati sebagian'));
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('belum_tercatat');
    expect(closeDay())->toMatchArray(['marked_absent' => 0, 'health' => 'perlu_pemeriksaan']);

    $result = app(CloseAttendanceDay::class)->releaseHold($this->school, '2026-10-19', $this->admin, 'Sudah diperiksa, terminal normal');
    expect($result)->toMatchArray(['marked_absent' => 1, 'health' => 'dilepas']);
    expect(dailyRows()[$this->absent]->attendance_status)->toBe('alpa_belum_diverifikasi');
    expect(fn () => app(CloseAttendanceDay::class)->releaseHold($this->school, '2026-10-19', $this->admin, 'Sudah dilepas sebelumnya'))->toThrow(Exception::class);
});

it('perintah attendance:close-day berjalan untuk semua sekolah', function () {
    atTime('06:30');
    scan($this->token);
    atTime('09:30');

    $this->artisan('attendance:close-day')->expectsOutputToContain('sekolah-a: wajib hadir 2, ditandai alpa sementara 1')->assertSuccessful();
    $this->artisan('attendance:close-day', ['--date' => 'bukan-tanggal'])->assertFailed();
});
