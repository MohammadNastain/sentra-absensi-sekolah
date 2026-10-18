<?php

namespace App\Domain\Audit;

use InvalidArgumentException;

/**
 * Allowlist field before/after per jenis entitas (ARCHITECTURE §12, U03).
 * Field di luar allowlist dibuang. Jenis entitas tanpa allowlist ditolak agar
 * entitas baru wajib dideklarasikan, bukan diam-diam menyimpan semua field.
 */
final class AuditRedactor
{
    /** @var array<string, list<string>> */
    private const ALLOWLIST = [
        'school' => ['code', 'npsn', 'name', 'education_levels', 'timezone', 'address', 'status'],
        'school_membership' => ['user_id', 'status', 'status_reason', 'roles'],
        'membership_invitation' => ['email', 'role_codes', 'expires_at', 'accepted_at', 'revoked_at', 'delivery_status'],
        'user' => ['email', 'name', 'status', 'two_factor_enabled'],
        'platform_admin' => ['user_id'],
        'academic_year' => ['name', 'start_date', 'end_date', 'is_active'],
        'teacher' => ['full_name', 'employee_number', 'membership_id', 'status'],
        'student' => ['student_number', 'nisn', 'full_name', 'date_of_birth', 'status'],
        'import_batch' => ['type', 'original_filename', 'file_sha256', 'total_rows', 'valid_rows', 'invalid_rows', 'committed_rows', 'status'],
        'student_credential' => ['student_id', 'credential_type', 'status', 'status_reason', 'replaces_credential_id'],
        'attendance_event' => ['student_id', 'event_type', 'result', 'reason_code', 'assist_reason_code', 'device_id', 'method'],
        'attendance_day' => ['date', 'reverted', 'count'],
        'attendance_settings' => ['absence_rule_enabled', 'absence_cutoff_time'],
        'device' => ['name', 'device_type', 'status', 'revoked_reason'],
        'holiday' => ['name', 'start_date', 'end_date'],
        'schedule' => ['scope_type', 'grade_level', 'class_id', 'day_of_week', 'check_in_start', 'check_in_deadline', 'late_grace_minutes', 'check_out_start', 'scheduled_check_out', 'effective_from', 'effective_until', 'is_active'],
        'class_enrollment' => ['student_id', 'class_id', 'start_date', 'end_date'],
        'school_class' => ['name', 'grade_level', 'academic_year_id', 'homeroom_teacher_id', 'status'],
        'auth' => ['email'],
    ];

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function redact(string $entityType, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $allowed = self::ALLOWLIST[$entityType]
            ?? throw new InvalidArgumentException("Jenis entitas audit '{$entityType}' belum memiliki allowlist.");

        return array_intersect_key($values, array_flip($allowed));
    }
}
