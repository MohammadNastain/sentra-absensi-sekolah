<?php

use Illuminate\Support\Facades\Route;

// Kontrak OpenAPI (apps/api/openapi.yaml) tidak boleh menyimpang dari rute yang terdaftar.

/**
 * @return list<string> "METHOD api/v1/path" dari bagian `paths` spesifikasi.
 */
function documentedOperations(): array
{
    $operations = [];
    $path = null;
    $inPaths = false;

    foreach (file(base_path('openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^paths:\s*$/', $line)) {
            $inPaths = true;

            continue;
        }
        if ($inPaths && preg_match('/^\S/', $line)) {
            break;
        }
        if ($inPaths && preg_match('/^  (\/\S*):\s*$/', $line, $m)) {
            $path = 'api/v1'.$m[1];
        } elseif ($inPaths && $path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
            $operations[] = strtoupper($m[1]).' '.$path;
        }
    }

    sort($operations);

    return $operations;
}

/**
 * @return list<string>
 */
function registeredOperations(): array
{
    $operations = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $operations[] = $method.' '.$route->uri();
        }
    }

    sort($operations);

    return $operations;
}

it('setiap rute API terdokumentasi di openapi.yaml', function () {
    expect(array_values(array_diff(registeredOperations(), documentedOperations())))->toBe([]);
});

it('setiap operasi di openapi.yaml memiliki rute nyata', function () {
    expect(array_values(array_diff(documentedOperations(), registeredOperations())))->toBe([]);
});

it('operationId unik dan jumlahnya sama dengan jumlah operasi', function () {
    preg_match_all('/operationId:\s*(\S+)/', (string) file_get_contents(base_path('openapi.yaml')), $ids);

    expect($ids[1])->toHaveCount(count(documentedOperations()))
        ->and(array_unique($ids[1]))->toHaveCount(count($ids[1]));
});
