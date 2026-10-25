<?php

use App\Domain\Entitlement\Feature;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequireSchoolFeature;
use App\Http\Middleware\RequireSchoolPermission;
use App\Http\Middleware\RequireTerminalHeader;
use App\Http\Middleware\ResolveSchoolMembership;
use App\Models\School;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Authorization\RouteMatrix;

// T08 (meta-test) dan T09 (matriks rute × peran) — TAHAP_1_FONDASI §8, TESTING_STRATEGY §4.

/**
 * @return array<string, RoutingRoute>
 */
function registeredRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.$route->uri()] = $route;
        }
    }

    ksort($routes);

    return $routes;
}

/**
 * @return list<string>
 */
function routeMiddleware(RoutingRoute $route): array
{
    return Route::gatherRouteMiddleware($route);
}

function hasAuth(RoutingRoute $route): bool
{
    return (bool) array_filter(routeMiddleware($route), fn (string $m) => str_starts_with($m, 'Illuminate\Auth\Middleware\Authenticate'));
}

it('T08: setiap rute terdaftar di matriks dan setiap entri matriks punya rute', function () {
    $registered = array_keys(registeredRoutes());
    $declared = array_keys(RouteMatrix::routes());

    expect(array_values(array_diff($registered, $declared)))->toBe([], 'Rute tanpa entri di RouteMatrix')
        ->and(array_values(array_diff($declared, $registered)))->toBe([], 'Entri RouteMatrix tanpa rute');
});

it('T08: middleware setiap rute sesuai deklarasi aksesnya', function () {
    foreach (registeredRoutes() as $key => $route) {
        $access = RouteMatrix::routes()[$key];
        $middleware = routeMiddleware($route);
        $isSchool = in_array(ResolveSchoolMembership::class, $middleware, true);
        $isPlatform = in_array(EnsurePlatformAdmin::class, $middleware, true);

        match (true) {
            $access === 'public' => expect(hasAuth($route))->toBeFalse("{$key} harus publik"),
            $access === 'authenticated' => expect(hasAuth($route) && ! $isSchool && ! $isPlatform)->toBeTrue("{$key} harus login saja"),
            $access === 'device' => expect(in_array(AuthenticateDevice::class, $middleware, true) && ! hasAuth($route) && ! $isSchool)->toBeTrue("{$key} harus memakai guard device"),
            $access === 'pairing' => expect(in_array(RequireTerminalHeader::class, $middleware, true) && ! hasAuth($route) && ! $isSchool)->toBeTrue("{$key} harus rute pairing terminal"),
            $access === 'platform' => expect(hasAuth($route) && $isPlatform && ! $isSchool)->toBeTrue("{$key} harus rute platform"),
            $access instanceof Permission => expect(hasAuth($route) && $isSchool
                && in_array(RequireSchoolPermission::class.':'.$access->value, $middleware, true))
                ->toBeTrue("{$key} harus rute sekolah dengan izin {$access->value}"),
        };

        // Setiap rute sekolah (/schools/{school}) wajib melewati validasi keanggotaan. Rute platform
        // (/platform/schools/{school}) justru tanpa keanggotaan: Super Admin bukan anggota (D-21).
        if (str_contains($route->uri(), 'schools/{school}') && ! $isPlatform) {
            expect($isSchool)->toBeTrue("{$key} memuat {school} tanpa ResolveSchoolMembership");
        }
    }
});

it('T08: gerbang fitur setiap rute sesuai RouteMatrix::featuresFor, dipasang sebelum izin peran', function () {
    foreach (registeredRoutes() as $key => $route) {
        $access = RouteMatrix::routes()[$key];
        $middleware = routeMiddleware($route);
        $gates = array_values(array_filter($middleware, fn (string $m) => str_starts_with($m, RequireSchoolFeature::class.':')));

        $expected = match (true) {
            $access instanceof Permission => RouteMatrix::featuresFor($key),
            $access === 'device' => [Feature::Attendance], // seluruh grup terminal yang sudah dipasangkan memakai modul absensi
            default => null,
        };

        if ($expected === null) {
            expect($gates)->toBe([], "{$key} tidak boleh digerbang fitur (rute inti/non-sekolah)");

            continue;
        }

        $codes = implode(',', array_map(fn (Feature $f) => $f->value, $expected));
        expect($gates)->toBe([RequireSchoolFeature::class.':'.$codes], "{$key} harus digerbang fitur {$codes}");

        if ($access instanceof Permission) {
            $permission = RequireSchoolPermission::class.':'.$access->value;
            expect(array_search($gates[0], $middleware, true))->toBeLessThan(array_search($permission, $middleware, true), "{$key}: gerbang fitur harus sebelum izin");
        }
    }
});

/**
 * @return array<string, array{0: string, 1: Permission}>
 */
function schoolRoutes(): array
{
    return collect(RouteMatrix::routes())
        ->filter(fn ($access) => $access instanceof Permission)
        ->mapWithKeys(fn (Permission $permission, string $key) => [$key => [$key, $permission]])
        ->all();
}

const SAMPLE_UUID = '00000000-0000-7000-8000-000000000001';

function concreteUri(string $uri): string
{
    $uri = str_replace(['{school}', '{date}', '{feature}'], ['sekolah-a', '2026-10-19', 'teachers'], $uri);

    return (string) preg_replace('/\{[A-Za-z]+\}/', SAMPLE_UUID, $uri);
}

/**
 * @return array<string, array{0: string}>
 */
function routesWithAccess(string $access): array
{
    return collect(RouteMatrix::routes())
        ->filter(fn ($declared) => $declared === $access)
        ->mapWithKeys(fn ($declared, string $key) => [$key => [$key]])
        ->all();
}

it('T09: rute sekolah tanpa login → 401', function (string $key) {
    [$method, $uri] = explode(' ', $key, 2);
    School::factory()->create(['code' => 'sekolah-a']);

    $this->json($method, '/'.concreteUri($uri))->assertUnauthorized();
})->with(fn () => array_map(fn ($row) => $row[0], schoolRoutes()));

it('T09: setiap peran mendapat akses sesuai izinnya pada setiap rute sekolah', function (string $key, Permission $permission, Role $role) {
    [$method, $uri] = explode(' ', $key, 2);
    $school = School::factory()->create(['code' => 'sekolah-a']);
    $user = User::factory()->create();
    $this->addMember($school, $user, [$role]);

    $response = $this->actingAs($user)->json($method, '/'.concreteUri($uri));

    if (in_array($permission, $role->permissions(), true)) {
        // Diizinkan: tidak boleh ditolak otorisasi. 404 hanya wajar bila ada ID anak yang memang tidak ada.
        expect($response->getStatusCode())->not->toBeIn([401, 403], "{$role->value} seharusnya diizinkan pada {$key}");
        if (! preg_match('/\{(?!school\})[A-Za-z]+\}/', $uri)) {
            expect($response->getStatusCode())->not->toBe(404, "{$key} tidak boleh 404 bagi {$role->value}");
        }
    } else {
        $response->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }
})->with(fn () => array_values(schoolRoutes()))->with(Role::cases());

it('T09: fitur dicabut → 403 FEATURE_NOT_ENTITLED walau perannya berhak; rute inti tidak terpengaruh', function (string $key, Permission $permission) {
    [$method, $uri] = explode(' ', $key, 2);
    $school = School::factory()->create(['code' => 'sekolah-a']);
    $user = User::factory()->create();
    $this->addMember($school, $user, [Role::SchoolAdmin]);
    $features = RouteMatrix::featuresFor($key);

    // Rute inti dicoba saat SEMUA fitur dicabut; rute lain saat fitur miliknya dicabut.
    foreach ($features ?? Feature::cases() as $feature) {
        setFeatureOverride($school->id, $feature->value, 'revoke');
    }

    $response = $this->actingAs($user)->json($method, '/'.concreteUri($uri));

    if ($features === null) {
        expect($response->json('code'))->not->toBe('FEATURE_NOT_ENTITLED', "{$key} adalah rute inti");
    } else {
        $response->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
    }
})->with(fn () => array_values(schoolRoutes()));

it('T09: rute yang butuh login → 401 tanpa login', function (string $key) {
    [$method, $uri] = explode(' ', $key, 2);

    $this->json($method, '/'.concreteUri($uri))->assertUnauthorized();
})->with(fn () => array_map(fn ($row) => $row[0], routesWithAccess('authenticated')));

it('T09: rute platform → 401 tanpa login, 403 bagi user biasa', function (string $key) {
    [$method, $uri] = explode(' ', $key, 2);
    School::factory()->create(['code' => 'sekolah-a']);

    $this->json($method, '/'.concreteUri($uri))->assertUnauthorized();

    $this->actingAs(User::factory()->withConfirmedTwoFactor()->create())
        ->json($method, '/'.concreteUri($uri))
        ->assertForbidden()
        ->assertJsonPath('code', 'PLATFORM_ADMIN_REQUIRED');
})->with(fn () => array_map(fn ($row) => $row[0], routesWithAccess('platform')));

it('T09: rute platform bagi Super Admin tanpa lulus 2FA → 403 TWO_FACTOR_REQUIRED', function (string $key) {
    [$method, $uri] = explode(' ', $key, 2);
    School::factory()->create(['code' => 'sekolah-a']);
    $admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());

    $this->actingAs($admin)->json($method, '/'.concreteUri($uri))
        ->assertForbidden()
        ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
})->with(fn () => array_map(fn ($row) => $row[0], routesWithAccess('platform')));
