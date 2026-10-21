<?php

namespace App\Domain\Imports;

use App\Domain\Teaching\SubjectResolver;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;

/**
 * Memvalidasi baris impor jadwal mengajar. Guru dicocokkan lewat nomor pegawai, atau nama lengkap bila nomor
 * tidak ada (harus tepat satu guru aktif); kelas dicocokkan dengan kelas aktif pada tahun ajaran aktif.
 * Bentrok jam (satu guru atau satu kelas dua pelajaran yang beririsan) dideteksi terhadap jadwal yang sudah ada
 * dan baris lain di file, memakai himpunan di memori.
 */
final class TeachingScheduleImportValidator implements ImportRowProcessor
{
    public const REQUIRED_HEADERS = ['hari', 'jam_mulai', 'jam_selesai', 'kelas', 'mapel', 'guru'];

    private const DAYS = [
        'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5,
        'sabtu' => 6, 'minggu' => 7, 'ahad' => 7,
    ];

    /** @var array<string, string> nama kelas huruf kecil → id */
    private array $classes = [];

    /** @var array<string, string> nomor pegawai huruf kecil → id guru */
    private array $teachersByNumber = [];

    /** @var array<string, list<string>> nama huruf kecil → id guru */
    private array $teachersByName = [];

    /** @var array<string, list<array{int, int}>> "guru|hari" → rentang menit */
    private array $teacherBusy = [];

    /** @var array<string, list<array{int, int}>> "kelas|hari" → rentang menit */
    private array $classBusy = [];

    private readonly SubjectResolver $subjects;

    public function __construct(private readonly string $schoolId)
    {
        $year = AcademicYear::query()->where('school_id', $schoolId)->where('is_active', true)->first();
        if ($year !== null) {
            foreach (SchoolClass::query()->where('school_id', $schoolId)->where('academic_year_id', $year->id)->where('status', SchoolClass::STATUS_ACTIVE)->get(['id', 'name']) as $class) {
                $this->classes[mb_strtolower($class->name)] = $class->id;
            }
        }

        foreach (Teacher::query()->where('school_id', $schoolId)->where('status', Teacher::STATUS_ACTIVE)->get(['id', 'full_name', 'employee_number']) as $teacher) {
            if ($teacher->employee_number !== null) {
                $this->teachersByNumber[mb_strtolower($teacher->employee_number)] = $teacher->id;
            }
            $this->teachersByName[mb_strtolower($teacher->full_name)][] = $teacher->id;
        }

        foreach (TeachingSlot::query()->where('school_id', $schoolId)->get(['class_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time']) as $slot) {
            $range = [self::minutes($slot->start_time), self::minutes($slot->end_time)];
            $this->teacherBusy["{$slot->teacher_id}|{$slot->day_of_week}"][] = $range;
            $this->classBusy["{$slot->class_id}|{$slot->day_of_week}"][] = $range;
        }

        $this->subjects = new SubjectResolver($schoolId);
    }

    public function validate(array $row): array
    {
        $errors = [];
        $add = function (string $field, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        $dayRaw = trim((string) ($row['hari'] ?? ''));
        $day = self::DAYS[mb_strtolower($dayRaw)] ?? (preg_match('/^[1-7]$/', $dayRaw) === 1 ? (int) $dayRaw : null);
        if ($day === null) {
            $add('hari', 'Hari harus Senin sampai Minggu (atau angka 1-7).');
        }

        $start = self::parseTime($row['jam_mulai'] ?? null);
        $end = self::parseTime($row['jam_selesai'] ?? null);
        if ($start === null) {
            $add('jam_mulai', 'Jam mulai harus berformat JJ:MM (contoh 07:00).');
        }
        if ($end === null) {
            $add('jam_selesai', 'Jam selesai harus berformat JJ:MM (contoh 08:20).');
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $add('jam_selesai', 'Jam selesai harus setelah jam mulai.');
        }

        $className = trim((string) ($row['kelas'] ?? ''));
        $classId = $this->classes[mb_strtolower($className)] ?? null;
        if ($className === '') {
            $add('kelas', 'Kelas wajib diisi.');
        } elseif ($classId === null) {
            $add('kelas', 'Kelas tidak ditemukan di tahun ajaran aktif (atau kelasnya diarsipkan).');
        }

        $subject = trim(preg_replace('/\s+/u', ' ', (string) ($row['mapel'] ?? '')) ?? '');
        if ($subject === '') {
            $add('mapel', 'Mapel wajib diisi.');
        } elseif (mb_strlen($subject) > 100) {
            $add('mapel', 'Nama mapel maksimal 100 karakter.');
        }

        $teacherRaw = trim((string) ($row['guru'] ?? ''));
        $teacherId = null;
        if ($teacherRaw === '') {
            $add('guru', 'Guru wajib diisi (nomor pegawai atau nama lengkap).');
        } else {
            $key = mb_strtolower($teacherRaw);
            $teacherId = $this->teachersByNumber[$key] ?? null;
            if ($teacherId === null) {
                $matches = $this->teachersByName[$key] ?? [];
                if (count($matches) === 1) {
                    $teacherId = $matches[0];
                } elseif (count($matches) > 1) {
                    $add('guru', 'Ada lebih dari satu guru dengan nama ini. Gunakan nomor pegawai.');
                } else {
                    $add('guru', 'Guru aktif dengan nomor pegawai atau nama ini tidak ditemukan.');
                }
            }
        }

        if ($errors === [] && $day !== null && $start !== null && $end !== null && $classId !== null && $teacherId !== null) {
            if (self::overlaps($this->teacherBusy["{$teacherId}|{$day}"] ?? [], $start, $end)) {
                $add('guru', 'Guru sudah mengajar pada jam yang beririsan di hari ini (di jadwal yang ada atau baris lain di file).');
            }
            if (self::overlaps($this->classBusy["{$classId}|{$day}"] ?? [], $start, $end)) {
                $add('kelas', 'Kelas sudah punya pelajaran pada jam yang beririsan di hari ini (di jadwal yang ada atau baris lain di file).');
            }
        }

        if ($errors !== [] || $day === null || $start === null || $end === null || $classId === null || $teacherId === null) {
            return ['normalized' => null, 'errors' => $errors];
        }

        $this->teacherBusy["{$teacherId}|{$day}"][] = [$start, $end];
        $this->classBusy["{$classId}|{$day}"][] = [$start, $end];

        return ['normalized' => [
            'class_id' => $classId,
            'teacher_id' => $teacherId,
            'subject' => $subject,
            'day_of_week' => $day,
            'start_time' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
            'end_time' => sprintf('%02d:%02d', intdiv($end, 60), $end % 60),
        ], 'errors' => []];
    }

    public function persist(array $normalized, User $actor): void
    {
        /** @var array{class_id: string, teacher_id: string, subject: string, day_of_week: int, start_time: string, end_time: string} $normalized */
        TeachingSlot::query()->create([
            'school_id' => $this->schoolId,
            'class_id' => $normalized['class_id'],
            'teacher_id' => $normalized['teacher_id'],
            'subject_id' => $this->subjects->idFor($normalized['subject']),
            'day_of_week' => $normalized['day_of_week'],
            'start_time' => $normalized['start_time'],
            'end_time' => $normalized['end_time'],
            'created_by_user_id' => $actor->id,
        ]);
    }

    /** Menerima JJ:MM, J:MM, JJ.MM, atau JJ:MM:DD; hasilnya menit sejak tengah malam. */
    private static function parseTime(?string $value): ?int
    {
        if ($value !== null && preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', trim($value), $m) === 1 && (int) $m[1] <= 23 && (int) $m[2] <= 59) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return null;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /** @param  list<array{int, int}>  $busy */
    private static function overlaps(array $busy, int $start, int $end): bool
    {
        foreach ($busy as [$s, $e]) {
            if ($start < $e && $s < $end) {
                return true;
            }
        }

        return false;
    }
}
