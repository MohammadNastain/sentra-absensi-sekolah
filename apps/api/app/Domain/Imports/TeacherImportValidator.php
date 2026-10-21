<?php

namespace App\Domain\Imports;

use App\Domain\Teaching\SubjectResolver;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Memvalidasi baris impor guru. Kolom `mapel` opsional berisi satu atau lebih nama mata pelajaran yang dipisah
 * titik koma (atau garis tegak); mapel yang belum ada dibuat saat commit. Nomor pegawai, bila diisi, unik per sekolah.
 */
final class TeacherImportValidator implements ImportRowProcessor
{
    public const REQUIRED_HEADERS = ['nama_lengkap'];

    private const MAX_SUBJECTS = 10;

    /** @var array<string, true> */
    private array $takenNumbers = [];

    private readonly SubjectResolver $subjects;

    public function __construct(private readonly string $schoolId)
    {
        foreach (Teacher::query()->where('school_id', $schoolId)->whereNotNull('employee_number')->pluck('employee_number') as $number) {
            $this->takenNumbers[mb_strtolower($number)] = true;
        }
        $this->subjects = new SubjectResolver($schoolId);
    }

    public function validate(array $row): array
    {
        $data = [
            'nomor_pegawai' => $row['nomor_pegawai'] ?? null,
            'nama_lengkap' => $row['nama_lengkap'] ?? null,
            'mapel' => $row['mapel'] ?? null,
            'email' => $row['email'] ?? null,
        ];

        $validator = Validator::make($data, [
            'nomor_pegawai' => ['nullable', 'string', 'max:50'],
            'nama_lengkap' => ['required', 'string', 'max:200'],
            'mapel' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
        ], [
            'email.email' => 'Format email tidak valid.',
        ], [
            'nomor_pegawai' => 'Nomor pegawai', 'nama_lengkap' => 'Nama lengkap', 'mapel' => 'Mapel', 'email' => 'Email',
        ]);

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['field' => $field, 'message' => $message];
            }
        }

        $number = is_string($data['nomor_pegawai']) ? $data['nomor_pegawai'] : null;
        if ($number !== null && ! $validator->errors()->has('nomor_pegawai') && isset($this->takenNumbers[mb_strtolower($number)])) {
            $errors[] = ['field' => 'nomor_pegawai', 'message' => 'Nomor pegawai sudah dipakai (di sekolah ini atau baris lain di file).'];
        }

        $subjects = [];
        if (is_string($data['mapel']) && ! $validator->errors()->has('mapel')) {
            foreach (preg_split('/[;|]/', $data['mapel']) ?: [] as $name) {
                $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
                if ($name !== '') {
                    $subjects[mb_strtolower($name)] = $name;
                }
            }
            $subjects = array_values($subjects);
            if (count($subjects) > self::MAX_SUBJECTS) {
                $errors[] = ['field' => 'mapel', 'message' => 'Maksimal '.self::MAX_SUBJECTS.' mapel per guru.'];
            }
            foreach ($subjects as $name) {
                if (mb_strlen($name) > 100) {
                    $errors[] = ['field' => 'mapel', 'message' => 'Nama mapel maksimal 100 karakter.'];
                    break;
                }
            }
        }

        if ($errors !== []) {
            return ['normalized' => null, 'errors' => $errors];
        }

        if ($number !== null) {
            $this->takenNumbers[mb_strtolower($number)] = true;
        }

        return ['normalized' => [
            'employee_number' => $number,
            'full_name' => $data['nama_lengkap'],
            'email' => is_string($data['email']) ? $data['email'] : null,
            'subjects' => $subjects,
        ], 'errors' => []];
    }

    public function persist(array $normalized, User $actor): void
    {
        /** @var array{employee_number: string|null, full_name: string, email: string|null, subjects: list<string>} $normalized */
        $teacher = Teacher::query()->create([
            'school_id' => $this->schoolId,
            'employee_number' => $normalized['employee_number'],
            'full_name' => $normalized['full_name'],
            'email' => $normalized['email'],
            'status' => Teacher::STATUS_ACTIVE,
        ]);

        $ids = array_values(array_unique(array_map($this->subjects->idFor(...), $normalized['subjects'])));
        $teacher->subjects()->attach($ids, ['school_id' => $this->schoolId]);
    }
}
