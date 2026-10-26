<?php

namespace App\Domain\Demo;

use App\Domain\Credentials\Actions\ManageCredentials;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Membangun sekolah demo lengkap (siswa, guru, kelas, jadwal, riwayat absensi, izin, kartu, dan LMS) dengan data fiktif.
 *
 * Tabel absensi bersifat append-only, jadi sekolah demo lama tidak dihapus: kodenya diganti dan statusnya
 * `terminated`, lalu sekolah baru dibuat dengan kode demo. Data acak dibuat deterministik (seed tetap) agar
 * tampilan demo stabil dari hari ke hari.
 */
final class DemoSchoolBuilder
{
    private const TZ = 'Asia/Jakarta';

    private const HISTORY_DAYS = 14;

    private const STUDENTS_PER_CLASS = 28;

    private const SUBJECTS = [
        'Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'IPA', 'IPS', 'PPKn', 'Seni Budaya', 'PJOK',
    ];

    private const PERIODS = [['07:00', '07:45'], ['07:45', '08:30'], ['08:30', '09:15'], ['09:45', '10:30'], ['10:30', '11:15'], ['11:15', '12:00']];

    private const FIRST = [
        'Ahmad', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fitri', 'Gita', 'Hadi', 'Indah', 'Joko', 'Kartika', 'Lukman', 'Maya', 'Nanda', 'Oktavia',
        'Putra', 'Qori', 'Rina', 'Sari', 'Tegar', 'Umar', 'Vina', 'Wahyu', 'Yusuf', 'Zahra', 'Aisyah', 'Bagas', 'Cahya', 'Dimas', 'Elsa',
        'Farhan', 'Hana', 'Irfan', 'Jihan', 'Kevin', 'Lestari', 'Mulyadi', 'Nabila', 'Raka', 'Salsa',
    ];

    private const LAST = [
        'Pratama', 'Wijaya', 'Santoso', 'Lestari', 'Hidayat', 'Saputra', 'Rahmawati', 'Nugroho', 'Kurniawan', 'Permata', 'Setiawan', 'Maulana',
        'Anggraini', 'Firmansyah', 'Handayani', 'Ramadhan', 'Utami', 'Siregar', 'Hakim', 'Purnama',
    ];

    public function __construct(
        private readonly DatabaseContext $context,
        private readonly ManageCredentials $credentials,
    ) {}

    /** Membuat sekolah demo baru (dan menonaktifkan yang lama). Mengembalikan kode sekolah. */
    public function build(): string
    {
        mt_srand(20260101);
        $today = CarbonImmutable::now(self::TZ)->startOfDay();

        $school = $this->context->runAsPlatform(function () {
            $code = DemoMode::schoolCode();
            DB::table('schools')->where('code', $code)->update([
                'code' => 'demo-arsip-'.now()->format('ymdHis'),
                'status' => 'terminated',
                'npsn' => null, // NPSN unik; dilepas agar sekolah demo baru bisa memakainya
            ]);

            $school = School::query()->forceCreate([
                'code' => $code, 'name' => 'SMP Sentra Demo', 'npsn' => '99999990', 'education_levels' => ['SMP'],
                'timezone' => self::TZ, 'status' => 'active',
            ]);
            DB::table('school_subscriptions')->insert(['school_id' => $school->id, 'plan_code' => 'premium', 'status' => 'active']);

            return $school;
        });

        $admin = User::query()->where('email', DemoMode::adminEmail())->first()
            ?? User::query()->forceCreate(['email' => DemoMode::adminEmail(), 'name' => 'Admin Demo', 'password' => Hash::make(Str::random(48)), 'email_verified_at' => now()]);
        DB::table('users')->where('id', $admin->id)->update([
            'password' => Hash::make(Str::random(48)),
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            'must_change_password' => false,
        ]);

        $studentIds = [];
        $this->context->runInSchool($school->id, function () use ($school, $admin, $today, &$studentIds) {
            $membership = SchoolMembership::query()->forceCreate(['school_id' => $school->id, 'user_id' => $admin->id, 'status' => SchoolMembership::STATUS_ACTIVE]);
            DB::table('membership_roles')->insert(['school_id' => $school->id, 'membership_id' => $membership->id, 'role_code' => Role::SchoolAdmin->value]);

            $yearStart = $today->month >= 7 ? $today->setDate($today->year, 7, 13) : $today->setDate($today->year - 1, 7, 13);
            $year = AcademicYear::query()->forceCreate([
                'school_id' => $school->id, 'name' => $yearStart->year.'/'.($yearStart->year + 1),
                'start_date' => $yearStart->toDateString(), 'end_date' => $yearStart->addYear()->subDays(17)->toDateString(), 'is_active' => true,
            ]);

            $subjects = $this->seedSubjects($school);
            $teachers = $this->seedTeachers($school, $subjects);
            $classes = $this->seedClasses($school, $year, $teachers);
            $studentIds = $this->seedStudents($school, $classes, $yearStart);
            $holidays = $this->seedCalendar($school, $yearStart);
            $slots = $this->seedTeaching($school, $classes, $teachers, $subjects, $admin);
            unset($slots);

            $schedules = Schedule::query()->where('school_id', $school->id)->pluck('id', 'day_of_week')->all();
            $this->seedAttendance($school, $admin, $classes, $studentIds, $schedules, $holidays, $today);
            $this->seedLms($school, $admin, $classes, $subjects, $studentIds, $today);
        });

        $this->seedCards($school, $admin, array_keys($studentIds));

        return $school->code;
    }

    /** @return list<string> subject id */
    private function seedSubjects(School $school): array
    {
        $ids = [];
        foreach (self::SUBJECTS as $name) {
            $ids[] = (string) DB::table('subjects')->insertGetId(['school_id' => $school->id, 'name' => $name], 'id');
        }

        return $ids;
    }

    /**
     * Guru ke-i mengajar mapel (i mod 8).
     *
     * @param  list<string>  $subjects
     * @return list<string> id guru
     */
    private function seedTeachers(School $school, array $subjects): array
    {
        $ids = [];
        for ($i = 0; $i < 24; $i++) {
            $teacher = Teacher::query()->forceCreate([
                'school_id' => $school->id, 'full_name' => $this->name($i + 100).($i % 2 === 0 ? ', S.Pd' : ', S.Si'),
                'employee_number' => sprintf('19%02d%02d%02d200%d01%d00%d', 70 + $i % 25, 1 + $i % 12, 1 + $i % 28, 3 + $i % 6, 1 + $i % 2, $i % 10),
                'status' => Teacher::STATUS_ACTIVE,
            ]);
            DB::table('teacher_subjects')->insert(['school_id' => $school->id, 'teacher_id' => $teacher->id, 'subject_id' => $subjects[$i % 8]]);
            $ids[] = $teacher->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $teachers
     * @return list<SchoolClass>
     */
    private function seedClasses(School $school, AcademicYear $year, array $teachers): array
    {
        $classes = [];
        foreach ([7, 8, 9] as $gi => $grade) {
            foreach (['A', 'B', 'C'] as $ci => $letter) {
                $classes[] = SchoolClass::query()->forceCreate([
                    'school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => $grade.$letter, 'grade_level' => $grade,
                    'homeroom_teacher_id' => $teachers[$gi * 3 + $ci], 'status' => SchoolClass::STATUS_ACTIVE,
                ]);
            }
        }

        return $classes;
    }

    /**
     * @param  list<SchoolClass>  $classes
     * @return array<string, string> student id => class id
     */
    private function seedStudents(School $school, array $classes, CarbonImmutable $yearStart): array
    {
        $map = [];
        $n = 0;
        foreach ($classes as $class) {
            for ($i = 0; $i < self::STUDENTS_PER_CLASS; $i++) {
                $n++;
                $birth = $yearStart->subYears($class->grade_level + 6)->addDays(mt_rand(0, 330));
                $student = Student::query()->forceCreate([
                    'school_id' => $school->id, 'student_number' => sprintf('%d%04d', $yearStart->year, $n),
                    'nisn' => sprintf('3%09d', 100000000 + $n * 37), 'full_name' => $this->name($n),
                    'date_of_birth' => $birth->toDateString(), 'status' => Student::STATUS_ACTIVE,
                ]);
                ClassEnrollment::query()->forceCreate([
                    'school_id' => $school->id, 'student_id' => $student->id, 'class_id' => $class->id, 'start_date' => $yearStart->toDateString(),
                ]);
                $map[$student->id] = $class->id;
            }
        }

        return $map;
    }

    /** @return list<string> tanggal libur (Y-m-d) */
    private function seedCalendar(School $school, CarbonImmutable $yearStart): array
    {
        foreach ([
            ['Hari Kemerdekaan RI', $yearStart->setDate($yearStart->year, 8, 17)],
            ['Hari Natal', $yearStart->setDate($yearStart->year, 12, 25)],
            ['Tahun Baru', $yearStart->setDate($yearStart->year + 1, 1, 1)],
        ] as [$name, $date]) {
            Holiday::query()->forceCreate(['school_id' => $school->id, 'name' => $name, 'start_date' => $date->toDateString(), 'end_date' => $date->toDateString()]);
        }

        foreach ([1, 2, 3, 4, 5] as $day) {
            Schedule::query()->forceCreate([
                'school_id' => $school->id, 'scope_type' => Schedule::SCOPE_SCHOOL, 'day_of_week' => $day,
                'check_in_start' => '06:00', 'check_in_deadline' => '07:00', 'late_grace_minutes' => 10,
                'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => $yearStart->toDateString(),
            ]);
        }

        return [
            $yearStart->setDate($yearStart->year, 8, 17)->toDateString(),
            $yearStart->setDate($yearStart->year, 12, 25)->toDateString(),
            $yearStart->setDate($yearStart->year + 1, 1, 1)->toDateString(),
        ];
    }

    /**
     * Jadwal mengajar: setiap kelas 6 jam pelajaran per hari Senin–Jumat, tanpa bentrok guru.
     *
     * @param  list<SchoolClass>  $classes
     * @param  list<string>  $teachers
     * @param  list<string>  $subjects
     */
    private function seedTeaching(School $school, array $classes, array $teachers, array $subjects, User $admin): int
    {
        $rows = [];
        foreach ($classes as $c => $class) {
            foreach ([1, 2, 3, 4, 5] as $day) {
                foreach (self::PERIODS as $p => [$start, $end]) {
                    $subject = ($c + $p + $day) % 8;
                    $teacher = $subject + 8 * (($c + $p) % 3);
                    $rows[] = [
                        'school_id' => $school->id, 'class_id' => $class->id, 'subject_id' => $subjects[$subject], 'teacher_id' => $teachers[$teacher],
                        'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'created_by_user_id' => $admin->id,
                    ];
                }
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('teaching_slots')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Riwayat absensi 14 hari sekolah terakhir sampai hari ini, izin/sakit yang disetujui, dan beberapa pengajuan izin menunggu.
     *
     * @param  list<SchoolClass>  $classes
     * @param  array<string, string>  $studentIds
     * @param  array<int, string>  $schedules
     * @param  list<string>  $holidays
     */
    private function seedAttendance(School $school, User $admin, array $classes, array $studentIds, array $schedules, array $holidays, CarbonImmutable $today): void
    {
        $days = [];
        for ($d = $today; count($days) < self::HISTORY_DAYS; $d = $d->subDay()) {
            if ($d->isWeekday() && ! in_array($d->toDateString(), $holidays, true)) {
                $days[] = $d;
            }
        }

        $events = [];
        $dailies = [];
        $leaves = [];

        foreach (array_reverse($days) as $day) {
            $date = $day->toDateString();
            $isToday = $day->equalTo($today);
            $sessionId = (string) DB::table('attendance_sessions')->insertGetId([
                'school_id' => $school->id, 'session_date' => $date, 'schedule_id' => $schedules[$day->dayOfWeekIso], 'timezone' => self::TZ,
                'status' => $isToday ? 'open' : 'closed', 'closed_at' => $isToday ? null : $day->setTime(23, 0)->toIso8601String(),
            ], 'id');

            foreach ($studentIds as $studentId => $classId) {
                $roll = mt_rand(1, 100);
                $base = [
                    'school_id' => $school->id, 'student_id' => $studentId, 'attendance_date' => $date,
                    'attendance_session_id' => $sessionId, 'class_id' => $classId,
                ];

                if ($isToday && $roll > 92) {
                    continue; // hari ini: sebagian siswa belum tercatat
                }

                if ($roll <= 6) {
                    $type = $roll <= 3 ? 'izin' : 'sakit';
                    $leaveId = (string) Str::uuid();
                    $leaves[] = [
                        'id' => $leaveId, 'school_id' => $school->id, 'student_id' => $studentId, 'leave_type' => $type, 'start_date' => $date, 'end_date' => $date,
                        'reason' => $type === 'izin' ? 'Keperluan keluarga' : 'Sakit demam, istirahat di rumah', 'status' => 'approved',
                        'requested_by_user_id' => $admin->id, 'decided_by_user_id' => $admin->id, 'decided_at' => $day->setTime(7, 30)->toIso8601String(),
                    ];
                    $dailies[] = $base + ['id' => (string) Str::uuid(), 'attendance_status' => $type, 'leave_request_id' => $leaveId];

                    continue;
                }

                if ($roll <= 8 && ! $isToday) {
                    $dailies[] = $base + [
                        'id' => (string) Str::uuid(), 'attendance_status' => 'alpa', 'absence_basis' => 'rule',
                        'verified_by_user_id' => $admin->id, 'verified_at' => $day->setTime(15, 0)->toIso8601String(),
                    ];

                    continue;
                }

                $late = $roll > 90 ? mt_rand(3, 25) : 0;
                $inAt = $day->setTime(6, 25)->addMinutes(mt_rand(0, 40) + ($late > 0 ? 35 : 0));
                $inId = (string) Str::uuid();
                $outId = $isToday ? null : (string) Str::uuid();
                $events[] = $this->event($school->id, $sessionId, $studentId, $date, 'check_in', $inAt, $inId, $late > 0, $late);
                if ($outId !== null) {
                    $events[] = $this->event($school->id, $sessionId, $studentId, $date, 'check_out', $day->setTime(14, 0)->addMinutes(mt_rand(0, 20)), $outId, false, 0);
                }
                $dailies[] = $base + [
                    'id' => (string) Str::uuid(), 'attendance_status' => $late > 0 ? 'terlambat' : 'hadir', 'late_minutes' => $late > 0 ? $late : null,
                    'check_in_event_id' => $inId, 'check_out_event_id' => $outId,
                ];
            }
        }

        // Pengajuan izin menunggu persetujuan untuk hari-hari ke depan.
        $pendingFor = array_slice(array_keys($studentIds), 5, 6);
        foreach ($pendingFor as $i => $studentId) {
            $start = $today->addDays(1 + $i % 3);
            $leaves[] = [
                'id' => (string) Str::uuid(), 'school_id' => $school->id, 'student_id' => $studentId, 'leave_type' => $i % 2 === 0 ? 'izin' : 'sakit',
                'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
                'reason' => $i % 2 === 0 ? 'Mengikuti acara keluarga di luar kota' : 'Kontrol ke dokter', 'status' => 'pending', 'requested_by_user_id' => $admin->id,
            ];
        }

        // leave_requests harus ada sebelum daily_attendance (FK), events sebelum daily (FK).
        foreach (array_chunk($this->normalize($leaves), 400) as $chunk) {
            DB::table('leave_requests')->insert($chunk);
        }
        foreach (array_chunk($events, 400) as $chunk) {
            DB::table('attendance_events')->insert($chunk);
        }
        foreach (array_chunk($this->normalize($dailies), 300) as $chunk) {
            DB::table('daily_attendance')->insert($chunk);
        }
    }

    /**
     * Menyamakan kunci semua baris (insert massal butuh kolom seragam).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalize(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $keys[$key] = null;
            }
        }

        return array_map(fn (array $row) => array_merge($keys, $row), $rows);
    }

    /** @return array<string, mixed> */
    private function event(string $schoolId, string $sessionId, string $studentId, string $date, string $type, CarbonImmutable $at, string $id, bool $late, int $lateMinutes): array
    {
        return [
            'id' => $id, 'school_id' => $schoolId, 'student_id' => $studentId, 'attendance_session_id' => $sessionId, 'attendance_date' => $date,
            'event_type' => $type, 'method' => 'rfid', 'result' => 'accepted', 'occurred_at_server' => $at->toIso8601String(),
            'is_late' => $type === 'check_in' ? $late : null, 'late_minutes' => $type === 'check_in' && $late ? $lateMinutes : null,
            'idempotency_key' => (string) Str::uuid(), 'response_snapshot' => '{}',
        ];
    }

    /**
     * Materi, tugas, dan pengumpulan (sebagian sudah dinilai) per kelas.
     *
     * @param  list<SchoolClass>  $classes
     * @param  list<string>  $subjects
     * @param  array<string, string>  $studentIds
     */
    private function seedLms(School $school, User $admin, array $classes, array $subjects, array $studentIds, CarbonImmutable $today): void
    {
        $byClass = [];
        foreach ($studentIds as $studentId => $classId) {
            $byClass[$classId][] = $studentId;
        }

        $materialTitles = ['Pengantar bab baru', 'Ringkasan materi dan contoh soal'];
        $assignmentTitles = ['Latihan soal pekan ini', 'Tugas proyek kelompok'];
        $comments = ['Bagus, pertahankan.', 'Sudah baik, perhatikan ketelitian.', 'Sangat rapi dan lengkap.', 'Cukup, tambah contoh pada bagian akhir.'];

        $materials = [];
        $submissions = [];

        foreach ($classes as $c => $class) {
            foreach ($materialTitles as $m => $title) {
                $materials[] = [
                    'school_id' => $school->id, 'class_id' => $class->id, 'subject_id' => $subjects[($c + $m) % 8], 'author_user_id' => $admin->id,
                    'title' => $title, 'body' => 'Silakan pelajari materi ini sebelum pertemuan berikutnya. Catat bagian yang belum dipahami dan tanyakan di kelas.',
                    'status' => 'published', 'published_at' => $today->subDays(6 - $m)->toIso8601String(),
                ];
            }

            foreach ($assignmentTitles as $a => $title) {
                $due = $a === 0 ? $today->subDays(2)->setTime(23, 59) : $today->addDays(4)->setTime(23, 59);
                $assignmentId = (string) Str::uuid();
                DB::table('lms_assignments')->insert([
                    'id' => $assignmentId, 'school_id' => $school->id, 'class_id' => $class->id, 'subject_id' => $subjects[($c + $a * 3) % 8], 'author_user_id' => $admin->id,
                    'title' => $title, 'instructions' => 'Kerjakan sesuai petunjuk di kelas dan kumpulkan sebelum batas waktu.', 'due_at' => $due->toIso8601String(),
                    'max_score' => 100, 'accept_late' => false, 'status' => 'published', 'published_at' => $today->subDays(5)->toIso8601String(),
                ]);

                foreach ($byClass[$class->id] ?? [] as $i => $studentId) {
                    $roll = mt_rand(1, 100);
                    if (($a === 0 && $roll > 88) || ($a === 1 && $roll > 30)) {
                        continue; // belum mengumpulkan
                    }
                    $row = [
                        'school_id' => $school->id, 'assignment_id' => $assignmentId, 'student_id' => $studentId, 'body' => 'Jawaban saya terlampir pada tugas ini.',
                        'status' => 'submitted', 'submitted_at' => $today->subDays($a === 0 ? 3 : 1)->setTime(mt_rand(8, 21), mt_rand(0, 59))->toIso8601String(), 'is_late' => false,
                        'score' => null, 'feedback' => null, 'graded_by_user_id' => null, 'graded_at' => null,
                    ];
                    if ($a === 0 && $roll <= 60) {
                        $row['status'] = 'graded';
                        $row['score'] = mt_rand(62, 100);
                        $row['feedback'] = $comments[$i % count($comments)];
                        $row['graded_by_user_id'] = $admin->id;
                        $row['graded_at'] = $today->subDay()->setTime(10, 0)->toIso8601String();
                    } elseif ($a === 0 && $roll <= 66) {
                        $row['status'] = 'returned_for_revision';
                        $row['feedback'] = 'Mohon lengkapi langkah pengerjaan, lalu kumpulkan ulang.';
                    }
                    $submissions[] = $row;
                }
            }
        }

        foreach (array_chunk($materials, 100) as $chunk) {
            DB::table('lms_materials')->insert($chunk);
        }
        foreach (array_chunk($submissions, 300) as $chunk) {
            DB::table('lms_submissions')->insert($chunk);
        }
    }

    /**
     * Kartu RFID untuk semua siswa lewat aksi resmi (HMAC dan audit seperti biasa).
     *
     * @param  list<string>  $studentIds
     */
    private function seedCards(School $school, User $admin, array $studentIds): void
    {
        foreach ($studentIds as $i => $studentId) {
            $this->credentials->registerRfid($school, $admin, $studentId, strtoupper(sprintf('D0%06X', $i + 1)).'AA');
        }
    }

    private function name(int $n): string
    {
        return self::FIRST[($n * 7) % count(self::FIRST)].' '.self::LAST[($n * 11 + intdiv($n, 3)) % count(self::LAST)];
    }
}
