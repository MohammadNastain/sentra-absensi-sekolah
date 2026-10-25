<?php

use App\Domain\Entitlement\EntitlementCalculator;
use App\Domain\Entitlement\Feature;

// ADR 0009 §3 — aturan akses efektif.

function resolveEntitlements(array $plan, array $overrides = []): array
{
    return (new EntitlementCalculator)->resolve($plan, $overrides);
}

it('registri fitur: induk ada, prasyarat tidak siklik, anak mengikuti kode induknya', function () {
    foreach (Feature::cases() as $feature) {
        $parent = $feature->parent();

        if ($parent !== null) {
            expect($feature->value)->toStartWith($parent->value.'.');
        }

        // Menelusuri prasyarat tidak boleh kembali ke fitur itu sendiri.
        $seen = [];
        $walk = function (Feature $f) use (&$walk, &$seen, $feature) {
            foreach ($f->prerequisites() as $p) {
                expect($p)->not->toBe($feature);
                if (! isset($seen[$p->value])) {
                    $seen[$p->value] = true;
                    $walk($p);
                }
            }
        };
        $walk($feature);
    }
});

it('fitur yang belum rilis tidak pernah efektif, walau ada di paket atau di-grant', function () {
    $state = resolveEntitlements(['students', 'academic', 'lms'], ['lms' => 'grant']);

    expect($state['lms'])->toBe(['enabled' => false, 'source' => 'unreleased', 'missing' => []]);
});

it('fitur dari paket efektif bila prasyarat terpenuhi', function () {
    $state = resolveEntitlements(['students', 'attendance', 'attendance.scan_qr']);

    expect($state['attendance']['enabled'])->toBeTrue()
        ->and($state['attendance']['source'])->toBe('plan')
        ->and($state['attendance.scan_qr']['enabled'])->toBeTrue()
        ->and($state['attendance.scan_rfid'])->toBe(['enabled' => false, 'source' => 'not_in_plan', 'missing' => []]);
});

it('override grant menambah fitur di luar paket, override revoke mengalahkan paket', function () {
    $state = resolveEntitlements(['students', 'attendance'], ['attendance.leave' => 'grant', 'attendance' => 'revoke']);

    expect($state['attendance'])->toBe(['enabled' => false, 'source' => 'override_revoke', 'missing' => []])
        // grant tidak menolong bila induknya dicabut
        ->and($state['attendance.leave'])->toBe(['enabled' => false, 'source' => 'blocked_by_dependency', 'missing' => ['attendance']]);

    $granted = resolveEntitlements(['students', 'attendance'], ['attendance.leave' => 'grant']);
    expect($granted['attendance.leave'])->toBe(['enabled' => true, 'source' => 'override_grant', 'missing' => []]);
});

it('fitur turunan tertahan saat prasyaratnya tidak efektif dan menyebut apa yang kurang', function () {
    // Paket tanpa data siswa: absensi dan akademik butuh students.
    $state = resolveEntitlements(['attendance', 'academic', 'academic.years']);

    expect($state['attendance'])->toBe(['enabled' => false, 'source' => 'blocked_by_dependency', 'missing' => ['students']])
        ->and($state['academic.years']['enabled'])->toBeFalse()
        ->and($state['academic.years']['missing'])->toBe(['academic']);
});

it('jadwal mengajar butuh guru, tahun ajaran, kelas, dan mapel', function () {
    $state = resolveEntitlements(['students', 'academic', 'academic.teaching_schedule']);

    expect($state['academic.teaching_schedule']['enabled'])->toBeFalse()
        ->and($state['academic.teaching_schedule']['missing'])->toBe(['teachers', 'academic.years', 'academic.classes', 'academic.subjects']);
});

it('tanpa paket dan tanpa override tidak ada fitur yang efektif', function () {
    $enabled = array_filter(resolveEntitlements([]), fn ($s) => $s['enabled']);

    expect($enabled)->toBe([]);
});
