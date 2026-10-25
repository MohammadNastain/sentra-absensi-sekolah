<?php

use App\Domain\Entitlement\EntitlementResolver;
use App\Domain\Entitlement\Feature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// ADR 0009 — cermin registri, paket, dan RLS tabel entitlement.

function subscribeAsPlatform(string $schoolId, string $plan, string $status = 'active'): void
{
    test()->dbContext()->runAsPlatform(fn () => DB::table('school_subscriptions')->insert([
        'school_id' => $schoolId, 'plan_code' => $plan, 'status' => $status,
    ]));
}

it('tabel features sama persis dengan enum Feature (kode dan induk)', function () {
    $db = DB::table('features')->orderBy('code')->pluck('parent_code', 'code')->all();
    $code = [];
    foreach (Feature::cases() as $feature) {
        $code[$feature->value] = $feature->parent()?->value;
    }
    ksort($code);

    expect($db)->toBe($code);
});

it('setiap paket tertutup terhadap dependensi (tidak memuat fitur tanpa prasyaratnya)', function () {
    foreach (DB::table('plans')->pluck('code') as $plan) {
        $codes = DB::table('plan_features')->where('plan_code', $plan)->pluck('feature_code')->all();

        $missing = [];
        foreach ($codes as $code) {
            foreach (Feature::from($code)->prerequisites() as $prerequisite) {
                if (! in_array($prerequisite->value, $codes, true)) {
                    $missing[] = "{$plan}: {$code} butuh {$prerequisite->value}";
                }
            }
        }

        expect($missing)->toBe([]);
    }
});

it('paket legacy berisi tepat semua fitur yang sudah rilis', function () {
    $legacy = DB::table('plan_features')->where('plan_code', 'legacy')->pluck('feature_code')->sort()->values()->all();
    $released = collect(Feature::cases())->filter->released()->map->value->sort()->values()->all();

    expect($legacy)->toBe($released);
});

it('paket Basic ⊂ Standard ⊂ Premium', function () {
    $of = fn (string $plan) => DB::table('plan_features')->where('plan_code', $plan)->pluck('feature_code')->all();

    expect(array_diff($of('basic'), $of('standard')))->toBe([])
        ->and(array_diff($of('standard'), $of('premium')))->toBe([])
        ->and(DB::table('plans')->where('code', 'standard')->value('price_per_student_idr'))->toBe(4000);
});

it('sentra_app tidak bisa mengubah registri fitur dan paket', function (string $sql) {
    expect(fn () => DB::statement($sql))->toThrow(QueryException::class);
})->with([
    "INSERT INTO features (code) VALUES ('hack')",
    "UPDATE plans SET price_per_student_idr = 0 WHERE code = 'premium'",
    "DELETE FROM plan_features WHERE plan_code = 'basic'",
]);

it('sekolah hanya melihat langganan dan override miliknya; platform melihat semua', function () {
    $a = createSchoolRow('sekolah-a', null);
    $b = createSchoolRow('sekolah-b', null);
    subscribeAsPlatform($a, 'basic');
    subscribeAsPlatform($b, 'premium');
    $this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->insert([
        ['school_id' => $a, 'feature_code' => 'attendance.leave', 'effect' => 'grant'],
        ['school_id' => $b, 'feature_code' => 'attendance.leave', 'effect' => 'revoke'],
    ]));

    expect(DB::table('school_subscriptions')->count())->toBe(0);

    $this->inSchool($a, function () {
        expect(DB::table('school_subscriptions')->pluck('plan_code')->all())->toBe(['basic'])
            ->and(DB::table('school_feature_overrides')->pluck('effect')->all())->toBe(['grant']);
    });
    $this->dbContext()->runAsPlatform(function () {
        expect(DB::table('school_subscriptions')->count())->toBe(2)
            ->and(DB::table('school_feature_overrides')->count())->toBe(2);
    });
});

it('sekolah tidak bisa menulis langganan atau override, termasuk miliknya sendiri', function () {
    $a = createSchoolRow('sekolah-a', null);
    subscribeAsPlatform($a, 'basic');

    $this->inSchool($a, function () use ($a) {
        // UPDATE/DELETE oleh sekolah mengenai 0 baris karena policy tulis hanya untuk platform.
        expect(DB::table('school_subscriptions')->update(['plan_code' => 'premium']))->toBe(0)
            ->and(DB::table('school_subscriptions')->delete())->toBe(0);

        // INSERT melanggar WITH CHECK dan membatalkan transaksi, jadi dijalankan terakhir.
        expect(fn () => DB::table('school_feature_overrides')->insert(['school_id' => $a, 'feature_code' => 'teachers', 'effect' => 'grant']))
            ->toThrow(QueryException::class);
    });
});

it('hanya satu langganan berjalan per sekolah, riwayat ended boleh banyak', function () {
    $a = createSchoolRow('sekolah-a', null);
    subscribeAsPlatform($a, 'basic', 'ended');
    subscribeAsPlatform($a, 'standard', 'ended');
    subscribeAsPlatform($a, 'premium');

    expect(fn () => subscribeAsPlatform($a, 'basic', 'trial'))->toThrow(QueryException::class);
});

it('override hanya satu per sekolah dan fitur', function () {
    $a = createSchoolRow('sekolah-a', null);
    $insert = fn (string $effect) => $this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->insert([
        'school_id' => $a, 'feature_code' => 'teachers', 'effect' => $effect,
    ]));

    $insert('grant');

    expect(fn () => $insert('revoke'))->toThrow(QueryException::class);
});

it('efek override hanya grant atau revoke', function () {
    $a = createSchoolRow('sekolah-a', null);

    expect(fn () => $this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->insert([
        'school_id' => $a, 'feature_code' => 'students', 'effect' => 'maybe',
    ])))->toThrow(QueryException::class);
});

it('resolver: paket Standard + override; sekolah tanpa langganan tidak punya fitur', function () {
    $a = createSchoolRow('sekolah-a', null);
    $none = createSchoolRow('sekolah-tanpa-paket', null);
    subscribeAsPlatform($a, 'basic');
    $this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->insert([
        ['school_id' => $a, 'feature_code' => 'attendance.leave', 'effect' => 'grant'],
        ['school_id' => $a, 'feature_code' => 'attendance.scan_rfid', 'effect' => 'revoke'],
    ]));
    $resolver = app(EntitlementResolver::class);

    $this->inSchool($a, fn () => expect($resolver->enabled($a))->toBe([
        'attendance', 'attendance.leave', 'attendance.reports', 'attendance.scan_qr', 'students',
    ]));
    $this->inSchool($none, fn () => expect($resolver->enabled($none))->toBe([]));
});

it('resolver: sekolah A tidak dipengaruhi paket sekolah B', function () {
    $a = createSchoolRow('sekolah-a', null);
    $b = createSchoolRow('sekolah-b', null);
    subscribeAsPlatform($a, 'basic');
    subscribeAsPlatform($b, 'standard');
    $resolver = app(EntitlementResolver::class);

    $this->inSchool($a, fn () => expect($resolver->allows($a, Feature::Teachers))->toBeFalse());
    $this->inSchool($b, fn () => expect($resolver->allows($b, Feature::Teachers))->toBeTrue());
    // Dari dalam konteks A, membaca B tidak menghasilkan apa pun (RLS), jadi hasilnya tertutup.
    $this->inSchool($a, fn () => expect($resolver->enabled($b))->toBe([]));
});
