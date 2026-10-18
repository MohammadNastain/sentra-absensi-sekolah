<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\AssistedAttendance;
use App\Domain\Attendance\ReviewQueue;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistedAttendanceRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\Device;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ringkasan absensi hari ini untuk dashboard. Angka diturunkan dari proyeksi `daily_attendance` dan penempatan
 * kelas berjalan; wali kelas hanya melihat kelas yang ia ampu (ClassScope). Polling dari UI, jadi ringan:
 * beberapa query teragregasi, tanpa N+1.
 */
final class AttendanceController extends Controller
{
    private const RECENT_LIMIT = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ScheduleResolver $resolver,
        private readonly ClassScope $scope,
        private readonly AssistedAttendance $assisted,
        private readonly ReviewQueue $review,
    ) {}

    public function today(School $school): JsonResponse
    {
        $school = $this->tenant->school();
        $local = Carbon::now()->setTimezone($school->timezone);
        $date = $local->toDateString();
        $classIds = $this->scope->classIds();

        $day = $this->resolver->resolveRange($school->id, $local->copy()->startOfDay(), $local->copy()->startOfDay())[$date] ?? null;
        $kind = $day?->holiday !== null ? 'holiday' : ($day?->schedule === null ? 'no_schedule' : 'school_day');

        // Daftar kelas dibatasi sebagai parameter terikat; kosong (wali kelas tanpa kelas) berarti tidak ada siswa.
        $scopeSql = '';
        $scopeBindings = [];
        if ($classIds !== null) {
            $scopeSql = $classIds === [] ? ' AND false' : ' AND en.class_id IN ('.implode(',', array_fill(0, count($classIds), '?')).')';
            $scopeBindings = $classIds;
        }

        $counts = DB::selectOne(
            "SELECT count(*) AS students,
                    count(*) FILTER (WHERE d.attendance_status = 'hadir') AS on_time,
                    count(*) FILTER (WHERE d.attendance_status = 'terlambat') AS late,
                    count(*) FILTER (WHERE d.check_out_event_id IS NOT NULL) AS checked_out
             FROM students s
             JOIN class_enrollments en ON en.school_id = s.school_id AND en.student_id = s.id
                 AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?)
             LEFT JOIN daily_attendance d ON d.school_id = s.school_id AND d.student_id = s.id AND d.attendance_date = ?
             WHERE s.school_id = ? AND s.status = 'active'{$scopeSql}",
            [$date, $date, $date, $school->id, ...$scopeBindings],
        );
        $students = (int) ($counts->students ?? 0);
        $onTime = (int) ($counts->on_time ?? 0);
        $late = (int) ($counts->late ?? 0);

        $recent = DB::select(
            "SELECT e.event_type, e.result, e.reason_code, e.occurred_at_server, e.is_late, s.full_name, c.name AS class_name
             FROM attendance_events e
             JOIN students s ON s.school_id = e.school_id AND s.id = e.student_id
             JOIN class_enrollments en ON en.school_id = e.school_id AND en.student_id = e.student_id
                 AND en.start_date <= e.attendance_date AND (en.end_date IS NULL OR en.end_date >= e.attendance_date)
             JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE e.school_id = ? AND e.attendance_date = ? AND e.result IN ('accepted', 'needs_review'){$scopeSql}
             ORDER BY e.occurred_at_server DESC LIMIT ".self::RECENT_LIMIT,
            [$school->id, $date, ...$scopeBindings],
        );

        $devices = Device::query()->where('status', Device::STATUS_ACTIVE)->get();
        $offline = $devices->filter(fn (Device $d) => ! $d->isOnline())->count();

        return response()->json(['data' => [
            'date' => $date,
            'timezone' => $school->timezone,
            'day' => ['kind' => $kind, 'holiday_name' => $day?->holiday?->name],
            'totals' => [
                'students' => $students,
                'present' => $onTime + $late,
                'on_time' => $onTime,
                'late' => $late,
                'not_recorded' => $students - $onTime - $late,
                'checked_out' => (int) ($counts->checked_out ?? 0),
            ],
            'recent' => array_map(fn ($r) => [
                'student_name' => (string) $r->full_name,
                'class_name' => (string) $r->class_name,
                'event_type' => $r->event_type,
                'result' => (string) $r->result,
                'is_late' => $r->is_late,
                'recorded_at_local' => Carbon::parse((string) $r->occurred_at_server)->setTimezone($school->timezone)->format('H.i'),
            ], $recent),
            'devices' => ['active' => $devices->count(), 'offline' => $offline],
        ]])->header('Cache-Control', 'no-store, private');
    }

    /** Pencarian minimal untuk mode bantuan dari dashboard petugas (tanpa izin melihat data siswa). */
    public function assistStudents(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->assisted->search($this->tenant->school(), $validated['q'])])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Pencatatan bantuan dari dashboard: akun petugas yang sudah login, aturan dan audit sama dengan di terminal. */
    public function assisted(AssistedAttendanceRequest $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{student_id: string, reason_code: string, note?: string|null, event_type?: string|null, idempotency_key: string} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->assisted->record($this->tenant->school(), null, $actor, $data)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function reviewQueue(School $school): JsonResponse
    {
        return response()->json(['data' => $this->review->pending($this->tenant->school())])->header('Cache-Control', 'no-store, private');
    }

    public function resolveReview(ReasonRequest $request, School $school, string $event): JsonResponse
    {
        $decision = $request->validate(['decision' => ['required', 'in:accept,reject']])['decision'];
        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->reason();
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Alasan minimal 10 karakter.']);
        }

        return response()->json(['data' => $this->review->resolve($this->tenant->school(), $actor, $event, $decision, $reason)]);
    }

    public function assistReport(Request $request, School $school): JsonResponse
    {
        $v = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $this->review->assistReport($this->tenant->school(), $v['from'], $v['to'])])->header('Cache-Control', 'no-store, private');
    }
}
