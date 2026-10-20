<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menghitung request scan terminal dan yang berakhir 5xx per sekolah per hari lokal, untuk penilaian kesehatan hari
 * (tingkat error melebihi ambang menahan aturan alpa). Disimpan di cache, bukan basis data, agar tetap tercatat
 * ketika basis data sendiri yang bermasalah.
 */
final class CountScanOutcomes
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->tenant->isActive()) {
            $school = $this->tenant->school();
            $key = self::key($school->id, Date::now()->setTimezone($school->timezone)->toDateString());
            self::bump("{$key}:total");
            if ($response->getStatusCode() >= 500) {
                self::bump("{$key}:errors");
            }
        }

        return $response;
    }

    public static function key(string $schoolId, string $date): string
    {
        return "scan-outcomes:{$schoolId}:{$date}";
    }

    /** @return array{total: int, errors: int} */
    public static function read(string $schoolId, string $date): array
    {
        $key = self::key($schoolId, $date);

        return ['total' => (int) Cache::get("{$key}:total", 0), 'errors' => (int) Cache::get("{$key}:errors", 0)];
    }

    private static function bump(string $key): void
    {
        try {
            if (! Cache::add($key, 1, now()->addDays(3))) {
                Cache::increment($key);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
