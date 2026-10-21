<?php

namespace App\Domain\Teaching;

use App\Models\Subject;

/**
 * Mencari mata pelajaran menurut nama (tanpa membedakan huruf besar/kecil) dan membuatnya bila belum ada.
 * Dipakai impor guru dan impor jadwal agar mapel baru tidak perlu didaftarkan manual terlebih dahulu.
 */
final class SubjectResolver
{
    /** @var array<string, string>|null nama huruf kecil → id */
    private ?array $ids = null;

    public function __construct(private readonly string $schoolId) {}

    public function idFor(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $key = mb_strtolower($name);
        $this->load();

        if (! isset($this->ids[$key])) {
            $this->ids[$key] = Subject::query()->create(['school_id' => $this->schoolId, 'name' => $name])->id;
        }

        return $this->ids[$key];
    }

    private function load(): void
    {
        if ($this->ids !== null) {
            return;
        }
        $this->ids = [];
        foreach (Subject::query()->where('school_id', $this->schoolId)->get(['id', 'name']) as $subject) {
            $this->ids[mb_strtolower($subject->name)] = $subject->id;
        }
    }
}
