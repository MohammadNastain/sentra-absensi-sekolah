<?php

namespace App\Domain\Imports;

final class StudentImportKind implements ImportKind
{
    public function type(): string
    {
        return ImportKinds::STUDENTS;
    }

    public function columns(): array
    {
        return ['nomor_induk', 'nisn', 'nama_lengkap', 'tanggal_lahir', 'kelas'];
    }

    public function requiredHeaders(): array
    {
        return StudentImportValidator::REQUIRED_HEADERS;
    }

    public function templateRows(): array
    {
        return [
            ['1001', '0012345678', 'Ahmad Rizky', '2012-05-01', '7A'],
            ['1002', '', 'Bunga Lestari', '', ''],
        ];
    }

    public function templateFilename(): string
    {
        return 'templat-impor-siswa.csv';
    }

    public function processor(string $schoolId): ImportRowProcessor
    {
        return new StudentImportValidator($schoolId);
    }
}
