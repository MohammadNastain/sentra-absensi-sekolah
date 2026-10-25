<?php

namespace App\Domain\Imports;

use App\Domain\Entitlement\Feature;
use InvalidArgumentException;

/**
 * Daftar jenis impor yang didukung, dikunci dengan nilai kolom `import_batches.type`.
 */
final class ImportKinds
{
    public const STUDENTS = 'students';

    public const TEACHERS = 'teachers';

    public const TEACHING_SCHEDULE = 'teaching_schedule';

    /** Fitur yang harus dimiliki sekolah untuk memakai jenis impor ini (ADR 0009). */
    public static function feature(string $type): Feature
    {
        return match ($type) {
            self::STUDENTS => Feature::Students,
            self::TEACHERS => Feature::Teachers,
            self::TEACHING_SCHEDULE => Feature::AcademicTeachingSchedule,
            default => throw new InvalidArgumentException("Jenis impor tidak dikenal: {$type}"),
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::STUDENTS, self::TEACHERS, self::TEACHING_SCHEDULE];
    }

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
