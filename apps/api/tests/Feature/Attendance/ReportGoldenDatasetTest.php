<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tahap 5D — golden dataset laporan. Angka di bawah dihitung manual dari tabel fixture dan disepakati sebagai
// acuan; mengubah rumus (D-14, ADR 0005 §5.7) berarti mengubah angka ini secara sadar.
//
// 10 hari sekolah (5–9 dan 12–16 Okt 2026), 3 kelas, 5 siswa (kode: h hadir, t terlambat(menit), i izin, s sakit,
// d dispensasi, a alpa final, v alpa sementara, b belum tercatat):
//   A1 (7A): h h h t10 h h t20 h s h
//   A2 (7A): h h i i t5 h h a v h
//   B1 (7B): h h h h d h h h h b
//   B2     : hari 1–5 di 7B: h h h h h; pindah ke 8A hari 6–10: h h h a a
//   C1 (8A): h x10, pulang awal pada hari 2 (disetujui), 5 (menunggu), 9 (ditolak)
//
// Rumus: persentase = (hadir + terlambat) / (hari efektif − izin − sakit − dispensasi).

const GOLDEN_DAYS = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'];

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a', 'timezone' => 'Asia/Jakarta']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->principal = User::factory()->create();
    $this->addMember($this->school, $this->principal, [Role::Principal]);
    $this->officer = User::factory()->create();
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
    Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00', 'Asia/Jakarta'));

    $this->inSchool($this->school, function () {
        $year = AcademicYear::query()->create(['school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
        $mk = fn (string $name, int $grade) => SchoolClass::query()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => $grade])->id;
        $this->c7a = $mk('7A', 7);
        $this->c7b = $mk('7B', 7);
        $this->c8a = $mk('8A', 8);
        $scheduleId = Schedule::query()->create([
            'school_id' => $this->school->id, 'scope_type' => 'school', 'day_of_week' => 1, 'check_in_start' => '06:00', 'check_in_deadline' => '07:00',
            'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => '2026-07-13',
        ])->id;

        $student = function (string $name, string $number, array $classes) {
            $s = Student::query()->create(['school_id' => $this->school->id, 'full_name' => $name, 'student_number' => $number, 'status' => 'active']);
            foreach ($classes as [$classId, $from]) {
                ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $s->id, 'class_id' => $classId, 'start_date' => $from]);
            }

            return $s->id;
        };
        $this->a1 = $student('A1 Satu', '1', [[$this->c7a, '2026-07-13']]);
        $this->a2 = $student('A2 Dua', '2', [[$this->c7a, '2026-07-13']]);
        $this->b1 = $student('B1 Tiga', '3', [[$this->c7b, '2026-07-13']]);
        $this->b2 = $student('B2 Empat', '4', [[$this->c7b, '2026-07-13']]);
        $this->c1 = $student('C1 Lima', '5', [[$this->c8a, '2026-07-13']]);

        $sessions = [];
        foreach (GOLDEN_DAYS as $day) {
            $sessions[$day] = (string) DB::scalar('INSERT INTO attendance_sessions (school_id, session_date, schedule_id, timezone) VALUES (?, ?, ?, ?) RETURNING id', [$this->school->id, $day, $scheduleId, 'Asia/Jakarta']);
        }
        $this->sessions = $sessions;

        $plan = [
            [$this->a1, array_fill(0, 10, $this->c7a), ['h', 'h', 'h', 't10', 'h', 'h', 't20', 'h', 's', 'h']],
            [$this->a2, array_fill(0, 10, $this->c7a), ['h', 'h', 'i', 'i', 't5', 'h', 'h', 'a', 'v', 'h']],
            [$this->b1, array_fill(0, 10, $this->c7b), ['h', 'h', 'h', 'h', 'd', 'h', 'h', 'h', 'h', 'b']],
            [$this->b2, [...array_fill(0, 5, $this->c7b), ...array_fill(0, 5, $this->c8a)], ['h', 'h', 'h', 'h', 'h', 'h', 'h', 'h', 'a', 'a']],
            [$this->c1, array_fill(0, 10, $this->c8a), array_fill(0, 10, 'h')],
        ];
        $early = [1 => 'approved', 4 => 'pending', 8 => 'rejected'];
        foreach ($plan as [$studentId, $classes, $codes]) {
            foreach (GOLDEN_DAYS as $i => $day) {
                goldenRow($this, $studentId, $classes[$i], $day, $codes[$i], $studentId === $this->c1 ? ($early[$i] ?? null) : null);
            }
        }
    });
});

afterEach(fn () => Carbon::setTestNow());

function goldenRow($t, string $studentId, string $classId, string $day, string $code, ?string $early): void
{
    $statusMap = ['h' => 'hadir', 't' => 'terlambat', 'i' => 'izin', 's' => 'sakit', 'd' => 'dispensasi', 'a' => 'alpa', 'v' => 'alpa_belum_diverifikasi', 'b' => 'belum_tercatat'];
    $status = $statusMap[$code[0]];
    $late = $code[0] === 't' ? (int) substr($code, 1) : null;
    $checkIn = null;
    if (in_array($status, ['hadir', 'terlambat'], true)) {
        $time = $status === 'terlambat' ? sprintf('07:%02d:00', $late) : '06:30:00';
        $checkIn = (string) DB::scalar(
            "INSERT INTO attendance_events (school_id, student_id, attendance_session_id, attendance_date, event_type, method, result, occurred_at_server, idempotency_key, response_snapshot)
             VALUES (?, ?, ?, ?, 'check_in', 'qr', 'accepted', ?, ?, '{}'::jsonb) RETURNING id",
            [$t->school->id, $studentId, $t->sessions[$day], $day, Carbon::parse("{$day} {$time}", 'Asia/Jakarta')->utc(), (string) Str::uuid()],
        );
    }
    $verified = $status === 'alpa' ? [$t->admin->id, now()] : [null, null];
    DB::insert(
        'INSERT INTO daily_attendance (school_id, student_id, attendance_date, attendance_session_id, class_id, attendance_status, check_in_event_id, late_minutes, early_leave, early_leave_status, absence_basis, verified_by_user_id, verified_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$t->school->id, $studentId, $day, $t->sessions[$day], $classId, $status, $checkIn, $late, $early !== null, $early,
            in_array($status, ['alpa', 'alpa_belum_diverifikasi'], true) ? 'rule' : null, ...$verified],
    );
}

function rep(string $path, ?User $as = null)
{
    $t = test();

    return $t->actingAs($as ?? $t->admin)->getJson('/api/v1/schools/sekolah-a/reports'.$path);
}

it('rekap: total dan per kelas cocok dengan hitungan manual (rumus D-14)', function () {
    $data = rep('/summary?from=2026-10-01&to=2026-10-31')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect($data['totals'])->toMatchArray([
        'effective_days' => 50, 'hadir' => 38, 'terlambat' => 3, 'izin' => 2, 'sakit' => 1, 'dispensasi' => 1, 'alpa' => 3,
        'alpa_belum_diverifikasi' => 1, 'belum_tercatat' => 1, 'present' => 41, 'excluded' => 4, 'denominator' => 46, 'attendance_percentage' => 89.13, 'late_minutes' => 35,
    ]);
    expect($data['totals']['early_leave'])->toBe(['total' => 3, 'approved' => 1, 'pending' => 1, 'rejected' => 1]);

    $byName = collect($data['classes'])->keyBy('class_name');
    expect($data['classes'])->toHaveCount(3)->and(array_column($data['classes'], 'class_name'))->toBe(['7A', '7B', '8A']);
    expect($byName['7A'])->toMatchArray(['effective_days' => 20, 'hadir' => 12, 'terlambat' => 3, 'izin' => 2, 'sakit' => 1, 'alpa' => 1, 'alpa_belum_diverifikasi' => 1, 'present' => 15, 'denominator' => 17, 'attendance_percentage' => 88.24]);
    expect($byName['7B'])->toMatchArray(['effective_days' => 15, 'hadir' => 13, 'dispensasi' => 1, 'belum_tercatat' => 1, 'present' => 13, 'denominator' => 14, 'attendance_percentage' => 92.86]);
    expect($byName['8A'])->toMatchArray(['effective_days' => 15, 'hadir' => 13, 'alpa' => 2, 'present' => 13, 'denominator' => 15, 'attendance_percentage' => 86.67]);
});

it('alpa sementara dan belum tercatat tidak dihitung sebagai alpa resmi maupun pengecualian', function () {
    $a2 = collect(rep('/students?from=2026-10-01&to=2026-10-31')->json('data'))->keyBy('student_name')['A2 Dua'];

    expect($a2)->toMatchArray(['alpa' => 1, 'alpa_belum_diverifikasi' => 1, 'present' => 6, 'excluded' => 2, 'denominator' => 8, 'attendance_percentage' => 75.0]);
});

it('rekap per siswa: tiap siswa cocok, siswa pindah kelas dihitung utuh lintas kelas', function () {
    $rows = collect(rep('/students?from=2026-10-01&to=2026-10-31')->assertOk()->json('data'))->keyBy('student_name');

    expect(array_keys($rows->all()))->toBe(['A1 Satu', 'A2 Dua', 'B1 Tiga', 'B2 Empat', 'C1 Lima']);
    expect($rows['A1 Satu'])->toMatchArray(['effective_days' => 10, 'hadir' => 7, 'terlambat' => 2, 'sakit' => 1, 'denominator' => 9, 'attendance_percentage' => 100.0, 'late_minutes' => 30]);
    expect($rows['B1 Tiga'])->toMatchArray(['dispensasi' => 1, 'belum_tercatat' => 1, 'denominator' => 9, 'attendance_percentage' => 88.89]);
    expect($rows['B2 Empat'])->toMatchArray(['effective_days' => 10, 'hadir' => 8, 'alpa' => 2, 'denominator' => 10, 'attendance_percentage' => 80.0]);
    expect($rows['C1 Lima']['early_leave'])->toBe(['total' => 3, 'approved' => 1, 'pending' => 1, 'rejected' => 1]);

    // Filter kelas memakai snapshot: B2 hanya 5 hari di 7B dan 5 hari di 8A.
    $inB = collect(rep("/students?from=2026-10-01&to=2026-10-31&class_id={$this->c7b}")->json('data'))->keyBy('student_name');
    expect($inB['B2 Empat'])->toMatchArray(['effective_days' => 5, 'hadir' => 5, 'alpa' => 0, 'attendance_percentage' => 100.0]);
    $inC = collect(rep("/students?from=2026-10-01&to=2026-10-31&class_id={$this->c8a}")->json('data'))->keyBy('student_name');
    expect($inC['B2 Empat'])->toMatchArray(['effective_days' => 5, 'hadir' => 3, 'alpa' => 2, 'attendance_percentage' => 60.0]);
});

it('rentang dan jenjang: sebagian rentang dan filter jenjang menyaring dengan benar', function () {
    // Minggu pertama saja (5–9 Okt): A2 punya izin 2 hari dan terlambat 1; belum ada alpa.
    $week = rep('/summary?from=2026-10-05&to=2026-10-09')->json('data');
    expect($week['totals'])->toMatchArray(['effective_days' => 25, 'izin' => 2, 'dispensasi' => 1, 'alpa' => 0, 'alpa_belum_diverifikasi' => 0, 'present' => 22, 'denominator' => 22, 'attendance_percentage' => 100.0]);

    $grade8 = rep('/summary?from=2026-10-01&to=2026-10-31&grade_level=8')->json('data');
    expect($grade8['classes'])->toHaveCount(1)->and($grade8['totals']['effective_days'])->toBe(15);

    rep('/summary?from=2025-01-01&to=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('to');
    rep('/summary?from=2026-10-31&to=2026-10-01')->assertUnprocessable();
    expect(rep('/summary?from=2025-12-31&to=2025-12-31')->json('data.totals.attendance_percentage'))->toBeNull();
});

it('keterlambatan: urut terbanyak, menyertakan menit', function () {
    $rows = rep('/lateness?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray(['student_name' => 'A1 Satu', 'class_name' => '7A', 'late_days' => 2, 'late_minutes' => 30])
        ->and($rows[1])->toMatchArray(['student_name' => 'A2 Dua', 'class_name' => '7A', 'late_days' => 1, 'late_minutes' => 5]);
});

it('harian: satu baris per siswa dengan jam masuk lokal, terurut jenjang-kelas-nama; filter kelas', function () {
    $rows = rep('/daily?date=2026-10-08')->assertOk()->json('data');
    expect(array_column($rows, 'student_name'))->toBe(['A1 Satu', 'A2 Dua', 'B1 Tiga', 'B2 Empat', 'C1 Lima']);
    expect($rows[0])->toMatchArray(['status' => 'terlambat', 'check_in' => '07.10', 'late_minutes' => 10, 'check_out' => null])
        ->and($rows[1])->toMatchArray(['status' => 'izin', 'check_in' => null]);

    $c1 = rep("/daily?date=2026-10-06&class_id={$this->c8a}")->json('data');
    expect($c1)->toHaveCount(1)->and(collect($c1)->firstWhere('student_name', 'C1 Lima'))->toMatchArray(['early_leave' => true, 'early_leave_status' => 'approved']);
    expect(rep('/daily?date=2026-10-11')->json('data'))->toBe([]);
});

it('bulanan: matriks siswa x tanggal untuk satu kelas (snapshot kelas, null di luar kelas)', function () {
    $data = rep("/monthly?month=2026-10&class_id={$this->c7b}")->assertOk()->json('data');

    expect($data['days'])->toBe(GOLDEN_DAYS);
    $byName = collect($data['students'])->keyBy('student_name');
    expect($byName['B1 Tiga']['statuses'])->toBe(['hadir', 'hadir', 'hadir', 'hadir', 'dispensasi', 'hadir', 'hadir', 'hadir', 'hadir', 'belum_tercatat']);
    expect($byName['B2 Empat']['statuses'])->toBe(['hadir', 'hadir', 'hadir', 'hadir', 'hadir', null, null, null, null, null]);
    rep('/monthly?month=2026-10')->assertUnprocessable();
    rep("/monthly?month=2026-13&class_id={$this->c7b}")->assertUnprocessable();
});

it('event ditolak/duplikat/perlu tinjauan pada rentang, hanya untuk peninjau', function () {
    $this->inSchool($this->school, function () {
        foreach ([['duplicate', 'ALREADY_CHECKED_IN'], ['rejected', 'UNKNOWN_CREDENTIAL'], ['needs_review', 'CHECKOUT_WITHOUT_CHECKIN']] as [$result, $reason]) {
            DB::insert(
                "INSERT INTO attendance_events (school_id, attendance_date, method, result, reason_code, occurred_at_server, idempotency_key, response_snapshot) VALUES (?, '2026-10-05', 'qr', ?, ?, ?, ?, '{}'::jsonb)",
                [$this->school->id, $result, $reason, Carbon::parse('2026-10-05 06:45:00', 'Asia/Jakarta')->utc(), (string) Str::uuid()],
            );
        }
    });

    rep('/events?from=2026-10-01&to=2026-10-31', $this->officer)->assertOk()->assertJsonCount(3, 'data');
    expect(rep('/events?from=2026-10-01&to=2026-10-31&result=rejected', $this->officer)->json('data.0'))->toMatchArray(['result' => 'rejected', 'reason_code' => 'UNKNOWN_CREDENTIAL', 'occurred_at' => '2026-10-05 06:45']);
    rep('/events?from=2026-10-01&to=2026-10-31&result=accepted', $this->officer)->assertUnprocessable();
    rep('/events?from=2026-10-01&to=2026-10-31', $this->principal)->assertForbidden();
});

it('otorisasi: kepala sekolah boleh melihat laporan; wali kelas hanya kelasnya; sekolah lain tidak melihat apa pun', function () {
    rep('/summary?from=2026-10-01&to=2026-10-31', $this->principal)->assertOk()->assertJsonPath('data.totals.effective_days', 50);

    $homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);
    $this->inSchool($this->school, function () use ($membership) {
        $teacher = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active']);
        SchoolClass::query()->where('id', $this->c7a)->update(['homeroom_teacher_id' => $teacher->id]);
    });
    $own = rep('/summary?from=2026-10-01&to=2026-10-31', $homeroom)->assertOk()->json('data');
    expect($own['totals']['effective_days'])->toBe(20)->and(array_column($own['classes'], 'class_name'))->toBe(['7A']);
    expect(rep("/summary?from=2026-10-01&to=2026-10-31&class_id={$this->c7b}", $homeroom)->json('data.classes'))->toBe([]);
    expect(rep('/students?from=2026-10-01&to=2026-10-31', $homeroom)->json('data'))->toHaveCount(2);

    $other = School::factory()->create(['code' => 'sekolah-b']);
    $outsider = User::factory()->create();
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);
    $this->actingAs($outsider)->getJson('/api/v1/schools/sekolah-b/reports/summary?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.totals.effective_days', 0);
});
