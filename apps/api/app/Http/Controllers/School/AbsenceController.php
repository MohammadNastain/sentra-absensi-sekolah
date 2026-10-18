<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\CloseAttendanceDay;
use App\Domain\Attendance\VerifyAbsences;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alpa sementara, verifikasi, penahanan aturan, dan pengaturan aturan alpa (ADR 0005 §5). Wali kelas hanya
 * melihat dan memverifikasi kelas yang ia ampu (ClassScope); penahanan dan pengaturan bukan haknya.
 */
final class AbsenceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClassScope $scope,
        private readonly VerifyAbsences $verifier,
        private readonly CloseAttendanceDay $closer,
        private readonly AuditLogger $audit,
    ) {}

    public function settings(School $school): JsonResponse
    {
        return response()->json(['data' => $this->settingsFor($this->tenant->school()->id)])->header('Cache-Control', 'no-store, private');
    }

    public function updateSettings(Request $request, School $school): JsonResponse
    {
        $school = $this->tenant->school();
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cutoff_time' => ['nullable', 'date_format:H:i', 'required_if:enabled,true'],
        ], ['cutoff_time.required_if' => 'Jam batas wajib diisi bila aturan alpa diaktifkan.']);

        $cutoff = $v['cutoff_time'] ?? null;
        if ($v['enabled'] && $cutoff !== null) {
            // Cutoff harus setelah batas masuk + toleransi terlama di jadwal aktif, agar siswa yang sah masih bisa terlambat.
            $latest = DB::scalar("SELECT max(check_in_deadline + (late_grace_minutes * interval '1 minute')) FROM schedules WHERE school_id = ? AND is_active", [$school->id]);
            if (is_string($latest) && $cutoff.':00' < substr($latest, 0, 8)) {
                throw ValidationException::withMessages(['cutoff_time' => 'Jam batas harus setelah batas masuk dan toleransi keterlambatan ('.substr($latest, 0, 5).').']);
            }
        }

        $before = $this->settingsFor($school->id);
        DB::update('UPDATE schools SET absence_rule_enabled = ?, absence_cutoff_time = ?, updated_at = now() WHERE id = ?', [$v['enabled'], $v['enabled'] ? $cutoff : null, $school->id]);
        $after = $this->settingsFor($school->id);
        $this->audit->record('school.attendance.absence_settings_updated', $school->id, 'attendance_settings', $school->id,
            ['absence_rule_enabled' => $before['enabled'], 'absence_cutoff_time' => $before['cutoff_time']],
            ['absence_rule_enabled' => $after['enabled'], 'absence_cutoff_time' => $after['cutoff_time']]);

        return response()->json(['data' => $after]);
    }

    public function pending(Request $request, School $school): JsonResponse
    {
        $school = $this->tenant->school();
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        $classIds = $this->scope->classIds();
        $scopeSql = '';
        $args = [$school->id, $date];
        if ($classIds !== null) {
            $scopeSql = $classIds === [] ? ' AND false' : ' AND d.class_id IN ('.implode(',', array_fill(0, count($classIds), '?')).')';
            array_push($args, ...$classIds);
        }

        $rows = DB::select(
            "SELECT d.student_id, s.full_name, s.student_number, c.name AS class_name, d.class_id, d.absence_basis
             FROM daily_attendance d
             JOIN students s ON s.school_id = d.school_id AND s.id = d.student_id
             LEFT JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE d.school_id = ? AND d.attendance_date = ? AND d.attendance_status = 'alpa_belum_diverifikasi'{$scopeSql}
             ORDER BY c.name, s.full_name LIMIT 1000",
            $args,
        );
        $health = DB::selectOne('SELECT status, reasons FROM attendance_day_health WHERE school_id = ? AND attendance_date = ?', [$school->id, $date]);

        return response()->json(['data' => [
            'date' => $date,
            'health' => ['status' => $health->status ?? null, 'reasons' => $health === null ? [] : json_decode((string) $health->reasons, true)],
            'items' => array_map(fn ($r) => [
                'student_id' => (string) $r->student_id,
                'student_name' => (string) $r->full_name,
                'student_number' => (string) $r->student_number,
                'class_id' => $r->class_id === null ? null : (string) $r->class_id,
                'class_name' => $r->class_name === null ? null : (string) $r->class_name,
                'absence_basis' => (string) $r->absence_basis,
            ], $rows),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Alasan minimal 10 karakter.']);

        return response()->json(['data' => $this->verifier->verify($this->tenant->school(), $actor, $v['date'], $v['student_ids'], trim($v['reason']), $this->scope->classIds())]);
    }

    public function markDisruption(ReasonRequest $request, School $school, string $date): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->closer->markDisruption($this->tenant->school(), $this->validDate($date), $actor, $this->longReason($request));

        return response()->json(['data' => ['date' => $date, 'status' => 'perlu_pemeriksaan']]);
    }

    public function releaseHold(ReasonRequest $request, School $school, string $date): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->closer->releaseHold($this->tenant->school(), $this->validDate($date), $actor, $this->longReason($request))]);
    }

    /** @return array{enabled: bool, cutoff_time: string|null} */
    private function settingsFor(string $schoolId): array
    {
        $row = DB::selectOne('SELECT absence_rule_enabled, absence_cutoff_time FROM schools WHERE id = ?', [$schoolId]);

        return ['enabled' => (bool) $row->absence_rule_enabled, 'cutoff_time' => $row->absence_cutoff_time === null ? null : substr((string) $row->absence_cutoff_time, 0, 5)];
    }

    private function validDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw ValidationException::withMessages(['date' => 'Tanggal tidak valid (Y-m-d).']);
        }

        return $date;
    }

    private function longReason(ReasonRequest $request): string
    {
        $reason = $request->reason();
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Alasan minimal 10 karakter.']);
        }

        return $reason;
    }
}
