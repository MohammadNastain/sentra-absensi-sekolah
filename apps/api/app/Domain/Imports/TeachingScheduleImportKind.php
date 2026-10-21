<?php

namespace App\Domain\Imports;

final class TeachingScheduleImportKind implements ImportKind
{
    public function type(): string
    {
        return ImportKinds::TEACHING_SCHEDULE;
    }

    public function columns(): array
    {
        return ['hari', 'jam_mulai', 'jam_selesai', 'kelas', 'mapel', 'guru'];
    }

    public function requiredHeaders(): array
    {
        return TeachingScheduleImportValidator::REQUIRED_HEADERS;
    }

    public function templateRows(): array
    {
        return [
            ['Senin', '07:00', '08:20', '7A', 'Matematika', '198001012005012001'],
            ['Senin', '08:20', '09:40', '7A', 'Bahasa Indonesia', 'Budi Santoso'],
        ];
    }

    public function templateFilename(): string
    {
        return 'templat-impor-jadwal-mengajar.csv';
    }

    public function processor(string $schoolId): ImportRowProcessor
    {
        return new TeachingScheduleImportValidator($schoolId);
    }
}
