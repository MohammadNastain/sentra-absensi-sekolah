<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan request ID untuk korelasi log, audit, dan respons error (ARCHITECTURE §15).
 * ID dari klien hanya dipakai bila formatnya aman; selain itu dibuat baru.
 */
final class RequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid7();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(): ?string
    {
        $id = request()->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
