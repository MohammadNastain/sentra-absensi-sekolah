<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Tenancy\DatabaseContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PlatformOverviewController extends Controller
{
    private const TREND_DAYS = 14;

    private const PRESENT = "('hadir', 'terlambat')";

    public function __construct(private readonly DatabaseContext $database) {}

    /**
     * Ringkasan lintas sekolah untuk dashboard Super Admin. Tabel tenant dilindungi RLS per sekolah
     * (konteks platform hanya membuka tabel sekolah), jadi angka dihitung per sekolah lalu dijumlahkan.
     */
    public function show(): JsonResponse
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $from = Carbon::parse($today)->subDays(self::TREND_DAYS - 1)->toDateString();

        $schools = DB::table('schools')->orderBy('name')->get(['id', 'code', 'name', 'status']);
        $byStatus = $schools->countBy('status');
        $byLevel = DB::query()->fromSub(DB::table('schools')->selectRaw('unnest(education_levels) AS level'), 's')
            ->selectRaw('level, count(*) AS total')
            ->groupBy('level')->orderByDesc('total')->pluck('total', 'level');

        // Paket berjalan per sekolah (satu langganan non-ended per sekolah); semua paket ditampilkan termasuk yang kosong.
        $planCounts = DB::table('school_subscriptions')->where('status', '<>', 'ended')
            ->selectRaw('plan_code, count(*) AS total')->groupBy('plan_code')->pluck('total', 'plan_code');
        $plans = DB::table('plans')->orderBy('price_per_student_idr')->get(['code', 'name', 'is_active'])
            ->filter(fn ($p) => $p->is_active || ($planCounts[$p->code] ?? 0) > 0)
            ->map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'total' => (int) ($planCounts[$p->code] ?? 0)])->values()->all();

        $students = 0;
        $teachers = 0;
        $daily = [];
        $schoolsToday = [];
        foreach ($schools as $school) {
            $stats = $this->database->runInSchool($school->id, function () use ($from, $today) {
                return [
                    'students' => DB::table('students')->where('status', 'active')->count(),
                    'teachers' => DB::table('teachers')->where('status', 'active')->count(),
                    'rows' => DB::table('daily_attendance')
                        ->whereBetween('attendance_date', [$from, $today])
                        ->where('attendance_status', '<>', 'belum_tercatat')
                        ->selectRaw('attendance_date::text AS date, count(*) AS recorded, count(*) FILTER (WHERE attendance_status IN '.self::PRESENT.') AS present')
                        ->groupBy('attendance_date')->get(),
                ];
            });
            $students += $stats['students'];
            $teachers += $stats['teachers'];
            foreach ($stats['rows'] as $row) {
                $daily[$row->date]['recorded'] = ($daily[$row->date]['recorded'] ?? 0) + (int) $row->recorded;
                $daily[$row->date]['present'] = ($daily[$row->date]['present'] ?? 0) + (int) $row->present;
            }
            if (in_array($school->status, ['trial', 'active'], true)) {
                $row = $stats['rows']->firstWhere('date', $today);
                $recorded = (int) ($row->recorded ?? 0);
                $present = (int) ($row->present ?? 0);
                $schoolsToday[] = [
                    'code' => $school->code,
                    'name' => $school->name,
                    'present' => $present,
                    'recorded' => $recorded,
                    'attendance_percentage' => $recorded > 0 ? round($present * 100 / $recorded, 2) : null,
                ];
            }
        }
        usort($schoolsToday, fn ($a, $b) => [$b['recorded'], $a['name']] <=> [$a['recorded'], $b['name']]);

        $trend = [];
        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $date = Carbon::parse($from)->addDays($i)->toDateString();
            $recorded = $daily[$date]['recorded'] ?? 0;
            $present = $daily[$date]['present'] ?? 0;
            $trend[] = [
                'date' => $date,
                'present' => $present,
                'recorded' => $recorded,
                'attendance_percentage' => $recorded > 0 ? round($present * 100 / $recorded, 2) : null,
            ];
        }

        return response()->json(['data' => [
            'as_of' => $today,
            'totals' => [
                'schools' => $schools->count(),
                'students' => $students,
                'teachers' => $teachers,
                'users' => DB::table('users')->count(),
            ],
            'schools_by_status' => collect(['trial', 'active', 'suspended', 'terminated'])
                ->map(fn ($s) => ['status' => $s, 'total' => (int) ($byStatus[$s] ?? 0)])->all(),
            'schools_by_plan' => $plans,
            'schools_by_level' => $byLevel->map(fn ($total, $level) => ['level' => $level, 'total' => (int) $total])->values()->all(),
            'attendance_trend' => $trend,
            'schools_today' => array_slice($schoolsToday, 0, 8),
        ]]);
    }
}
