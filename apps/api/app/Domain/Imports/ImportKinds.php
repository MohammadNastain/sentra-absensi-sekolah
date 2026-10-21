<?php

namespace App\Domain\Imports;

use InvalidArgumentException;

/**
 * Daftar jenis impor yang didukung, dikunci dengan nilai kolom `import_batches.type`.
 */
final class ImportKinds
{
    public const STUDENTS = 'students';

    public const TEACHERS = 'teachers';

    public const TEACHING_SCHEDULE = 'teaching_schedule';

    public static function get(string $type): ImportKind
    {
        return match ($type) {
            self::STUDENTS => new StudentImportKind,
            self::TEACHERS => new TeacherImportKind,
            self::TEACHING_SCHEDULE => new TeachingScheduleImportKind,
            default => throw new InvalidArgumentException("Jenis impor tidak dikenal: {$type}"),
        };
    }
}
