<?php

use App\Casts\PostgresTextArray;
use App\Models\School;

it('mengonversi text[] PostgreSQL ke array dan sebaliknya', function () {
    $cast = new PostgresTextArray;
    $model = new School;

    expect($cast->get($model, 'education_levels', '{SMP,MTs}', []))->toBe(['SMP', 'MTs'])
        ->and($cast->get($model, 'education_levels', '{}', []))->toBe([])
        ->and($cast->get($model, 'education_levels', null, []))->toBeNull()
        ->and($cast->set($model, 'education_levels', ['SD', 'MI'], []))->toBe('{SD,MI}')
        ->and($cast->set($model, 'education_levels', null, []))->toBeNull();
});

it('menolak elemen yang butuh escaping alih-alih meng-escape diam-diam', function (string $bad) {
    (new PostgresTextArray)->set(new School, 'education_levels', [$bad], []);
})->with(['koma' => 'a,b', 'kurung' => 'a}', 'kutip' => 'a"b', 'spasi' => 'a b', 'kosong' => ''])
    ->throws(InvalidArgumentException::class);
