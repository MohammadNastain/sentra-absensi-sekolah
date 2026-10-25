<?php

namespace App\Support\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Merender semua error API sebagai RFC 9457 problem+json dengan `code` stabil
 * (ARCHITECTURE §11). Pesan internal tidak pernah dibocorkan untuk 5xx.
 */
final class ProblemRenderer
{
    private const TITLES = [
        400 => ['BAD_REQUEST', 'Permintaan tidak valid.'],
        401 => ['UNAUTHENTICATED', 'Anda perlu masuk terlebih dahulu.'],
        403 => ['FORBIDDEN', 'Akses ditolak.'],
        404 => ['NOT_FOUND', 'Data tidak ditemukan.'],
        405 => ['METHOD_NOT_ALLOWED', 'Metode tidak diizinkan.'],
        409 => ['CONFLICT', 'Terjadi konflik dengan data saat ini.'],
        419 => ['CSRF_TOKEN_MISMATCH', 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.'],
        422 => ['VALIDATION_FAILED', 'Data yang dikirim tidak valid.'],
        429 => ['TOO_MANY_REQUESTS', 'Terlalu banyak percobaan. Coba lagi nanti.'],
        503 => ['SERVICE_UNAVAILABLE', 'Layanan sedang tidak tersedia.'],
    ];

    public function render(Throwable $e, Request $request): JsonResponse
    {
        $headers = [];
        $extra = [];

        if ($e instanceof ApiProblem) {
            $status = $e->getStatusCode();
            [$code, $title, $detail] = [$e->problemCode, $e->title, $e->detail];
            $headers = $e->getHeaders();
            $extra = $e->extensions;
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            [$code, $title] = self::TITLES[422];
            $detail = null;
            $extra['errors'] = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            [$code, $title] = self::TITLES[401];
            $detail = null;
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $title] = self::TITLES[$status] ?? ['HTTP_ERROR', 'Permintaan tidak dapat diproses.'];
            $detail = null;
            $headers = $e->getHeaders();
        } else {
            $status = 500;
            [$code, $title] = ['INTERNAL_ERROR', 'Terjadi kesalahan pada server.'];
            $detail = config('app.debug') ? $e->getMessage() : null;
        }

        $body = array_filter([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => $request->attributes->get(RequestId::ATTRIBUTE),
            ...$extra,
        ], fn ($value) => $value !== null);

        return new JsonResponse($body, $status, [
            ...$headers,
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
