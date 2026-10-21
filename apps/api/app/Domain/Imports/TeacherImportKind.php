<?php

namespace App\Domain\Imports;

final class TeacherImportKind implements ImportKind
{
    public function type(): string
    {
        return ImportKinds::TEACHERS;
    }

    public function columns(): array
    {
        return ['nomor_pegawai', 'nama_lengkap', 'mapel', 'email'];
    }

    public function requiredHeaders(): array
    {
        return TeacherImportValidator::REQUIRED_HEADERS;
    }

    public function templateRows(): array
    {
        return [
            ['198001012005012001', 'Siti Rahma', 'Matematika; IPA', 'siti@sekolah.sch.id'],
            ['', 'Budi Santoso', 'Bahasa Indonesia', ''],
        ];
    }

    public function templateFilename(): string
    {
        return 'templat-impor-guru.csv';
    }

    public function processor(string $schoolId): ImportRowProcessor
    {
        return new TeacherImportValidator($schoolId);
    }
}
