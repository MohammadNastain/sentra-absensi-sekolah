<?php

namespace App\Domain\Imports;

/**
 * Satu jenis impor (siswa, guru, jadwal mengajar): kolom file, templat, dan pemrosesnya.
 */
interface ImportKind
{
    public function type(): string;

    /** @return list<string> kolom file, urut seperti pada templat */
    public function columns(): array;

    /** @return list<string> */
    public function requiredHeaders(): array;

    /** @return list<list<string>> baris contoh pada templat */
    public function templateRows(): array;

    public function templateFilename(): string;

    public function processor(string $schoolId): ImportRowProcessor;
}
