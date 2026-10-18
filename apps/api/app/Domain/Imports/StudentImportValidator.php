<?php

namespace App\Domain\Imports;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Facades\Validator;

/**
 * Memvalidasi baris impor siswa. Keunikan diperiksa terhadap isi file dan data sekolah yang sudah ada,
 * memakai himpunan di memori (bukan satu query per baris). Kolom `kelas` opsional dan dicocokkan dengan nama
 * kelas aktif pada tahun ajaran aktif; siswa ditempatkan mulai tanggal mulai tahun ajaran itu.
 */
final class StudentImportValidator
{
    public const REQUIRED_HEADERS = ['nomor_induk', 'nama_lengkap'];

    /** @var array<string, true> */
    private array $takenNumbers = [];

    /** @var array<string, true> */
    private array $takenNisns = [];

    /** @var array<string, string> nama kelas huruf kecil → id */
    private array $classes = [];

    private ?string $enrollStartDate = null;

    public function __construct(private readonly string $schoolId)
    {
        foreach (Student::query()->where('school_id', $schoolId)->get(['student_number', 'nisn']) as $existing) {
            $this->takenNumbers[mb_strtolower($existing->student_number)] = true;
            if ($existing->nisn !== null) {
                $this->takenNisns[$existing->nisn] = true;
            }
        }

        $year = AcademicYear::query()->where('school_id', $schoolId)->where('is_active', true)->first();
        if ($year !== null) {
            $this->enrollStartDate = $year->start_date->toDateString();
            foreach (SchoolClass::query()->where('school_id', $schoolId)->where('academic_year_id', $year->id)->where('status', SchoolClass::STATUS_ACTIVE)->get(['id', 'name']) as $class) {
                $this->classes[mb_strtolower($class->name)] = $class->id;
            }
        }
    }

    public function enrollStartDate(): ?string
    {
        return $this->enrollStartDate;
    }

    /**
     * @param  list<string>  $headers
     * @return list<string> header wajib yang tidak ada
     */
    public static function missingHeaders(array $headers): array
    {
        return array_values(array_diff(self::REQUIRED_HEADERS, $headers));
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{normalized: array<string, mixed>|null, errors: list<array{field: string, message: string}>}
     */
    public function validate(array $row): array
    {
        $data = [
            'nomor_induk' => $row['nomor_induk'] ?? null,
            'nisn' => $row['nisn'] ?? null,
            'nama_lengkap' => $row['nama_lengkap'] ?? null,
            'tanggal_lahir' => $this->normalizeDate($row['tanggal_lahir'] ?? null),
            'kelas' => $row['kelas'] ?? null,
        ];

        $validator = Validator::make($data, [
            'nomor_induk' => ['required', 'string', 'max:50'],
            'nisn' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
            'nama_lengkap' => ['required', 'string', 'max:200'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kelas' => ['nullable', 'string', 'max:100'],
        ], [
            'nisn.regex' => 'NISN harus 10 digit angka (pastikan kolom berformat teks agar angka nol di depan tidak hilang).',
            'tanggal_lahir.date_format' => 'Tanggal lahir harus berformat TTTT-BB-HH atau HH/BB/TTTT.',
            'tanggal_lahir.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
        ], [
            'nomor_induk' => 'Nomor induk', 'nisn' => 'NISN', 'nama_lengkap' => 'Nama lengkap',
            'tanggal_lahir' => 'Tanggal lahir', 'kelas' => 'Kelas',
        ]);

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['field' => $field, 'message' => $message];
            }
        }

        $number = is_string($data['nomor_induk']) ? $data['nomor_induk'] : null;
        $nisn = is_string($data['nisn']) ? $data['nisn'] : null;
        $classId = null;

        if ($number !== null && ! $validator->errors()->has('nomor_induk') && isset($this->takenNumbers[mb_strtolower($number)])) {
            $errors[] = ['field' => 'nomor_induk', 'message' => 'Nomor induk sudah dipakai (di sekolah ini atau baris lain di file).'];
        }
        if ($nisn !== null && ! $validator->errors()->has('nisn') && isset($this->takenNisns[$nisn])) {
            $errors[] = ['field' => 'nisn', 'message' => 'NISN sudah dipakai (di sekolah ini atau baris lain di file).'];
        }
        if (is_string($data['kelas']) && ! $validator->errors()->has('kelas')) {
            $classId = $this->classes[mb_strtolower($data['kelas'])] ?? null;
            if ($classId === null) {
                $errors[] = ['field' => 'kelas', 'message' => 'Kelas tidak ditemukan di tahun ajaran aktif (atau kelasnya diarsipkan).'];
            }
        }

        if ($errors !== []) {
            return ['normalized' => null, 'errors' => $errors];
        }

        // Baris valid "memesan" nomornya agar duplikat di baris berikutnya ditolak.
        if ($number !== null) {
            $this->takenNumbers[mb_strtolower($number)] = true;
        }
        if ($nisn !== null) {
            $this->takenNisns[$nisn] = true;
        }

        return ['normalized' => [
            'student_number' => $number,
            'nisn' => $nisn,
            'full_name' => $data['nama_lengkap'],
            'date_of_birth' => $data['tanggal_lahir'],
            'class_id' => $classId,
        ], 'errors' => []];
    }

    /** Menerima TTTT-BB-HH dan HH/BB/TTTT (atau HH-BB-TTTT); selain itu dikembalikan apa adanya agar ditolak validator. */
    private function normalizeDate(?string $value): ?string
    {
        if ($value !== null && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $value;
    }
}
