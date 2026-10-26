<?php

namespace App\Http\Middleware;

use App\Domain\Demo\DemoMode;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sekolah demo terbuka untuk siapa saja, jadi unggah berkas (lampiran, impor) dimatikan agar penyimpanan server
 * tidak bisa dipenuhi dan berkas sembarang tidak masuk ke server.
 */
final class BlockDemoUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->allFiles() !== [] && DemoMode::isDemoSchoolCode((string) $request->route('school'))) {
            throw ApiProblem::demoRestricted('Unggah berkas dinonaktifkan di mode demo.');
        }

        return $next($request);
    }
}
