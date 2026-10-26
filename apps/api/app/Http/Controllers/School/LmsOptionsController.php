<?php

namespace App\Http\Controllers\School;

use App\Domain\Tenancy\LmsScope;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

/**
 * Pilihan kelas-mapel untuk formulir LMS. Guru tidak memegang izin akademik, jadi daftar kelas dan mapel dari API
 * akademik tidak tersedia baginya; endpoint ini memberi hanya pasangan yang boleh ia kelola (dari jadwal mengajar).
 * Admin boleh semua kombinasi: `pairs` null, dan `classes`/`subjects` memuat seluruh kelas aktif dan mapel.
 */
final class LmsOptionsController extends Controller
{
    public function __construct(private readonly LmsScope $scope) {}

    public function index(): JsonResponse
    {
        if ($this->scope->managesAll()) {
            return response()->json(['data' => [
                'pairs' => null,
                'classes' => SchoolClass::query()->where('status', 'active')->orderByRaw('lower(name)')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'subjects' => Subject::query()->orderByRaw('lower(name)')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name])->all(),
            ]]);
        }

        $pairs = $this->scope->teachingPairs();
        $classes = SchoolClass::query()->whereIn('id', array_column($pairs, 'class_id'))->pluck('name', 'id');
        $subjects = Subject::query()->whereIn('id', array_column($pairs, 'subject_id'))->pluck('name', 'id');

        $named = array_map(fn (array $p) => [
            'class' => ['id' => $p['class_id'], 'name' => (string) ($classes[$p['class_id']] ?? '')],
            'subject' => ['id' => $p['subject_id'], 'name' => (string) ($subjects[$p['subject_id']] ?? '')],
        ], $pairs);
        usort($named, fn (array $a, array $b) => [$a['class']['name'], $a['subject']['name']] <=> [$b['class']['name'], $b['subject']['name']]);

        return response()->json(['data' => [
            'pairs' => $named,
            'classes' => collect($named)->pluck('class')->unique('id')->values()->all(),
            'subjects' => collect($named)->pluck('subject')->unique('id')->values()->all(),
        ]]);
    }
}
