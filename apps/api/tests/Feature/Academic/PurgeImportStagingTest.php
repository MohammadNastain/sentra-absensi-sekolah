<?php

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Tahap 2F — retensi staging impor 30 hari (D-26)

beforeEach(function () {
    Storage::fake('local');
    $this->schoolA = School::factory()->create(['code' => 'sekolah-a']);
    $this->schoolB = School::factory()->create(['code' => 'sekolah-b']);
    $this->user = User::factory()->create();
});

function seedBatch(School $school, int $daysOld, string $status = 'validated'): ImportBatch
{
    $t = test();
    $path = "imports/{$school->id}/".uniqid().'.csv';
    Storage::disk('local')->put($path, "nomor_induk,nama\n1,A\n");

    return $t->inSchool($school, function () use ($school, $daysOld, $status, $t, $path) {
        $batch = ImportBatch::query()->create([
            'school_id' => $school->id, 'status' => $status, 'original_filename' => 'x.csv', 'storage_path' => $path,
            'file_sha256' => str_repeat('a', 64), 'uploaded_by_user_id' => $t->user->id,
        ]);
        DB::table('import_batches')->where('id', $batch->id)->update(['created_at' => now()->subDays($daysOld)]);
        ImportRow::query()->create(['school_id' => $school->id, 'batch_id' => $batch->id, 'row_number' => 2, 'payload' => ['a' => 1], 'status' => 'valid']);

        return $batch;
    });
}

it('menghapus batch, baris, dan file yang lebih tua dari 30 hari di semua sekolah, apa pun statusnya', function () {
    $old = seedBatch($this->schoolA, 31, 'committed');
    $stuck = seedBatch($this->schoolB, 45, 'validating');

    $this->artisan('imports:purge')->expectsOutputToContain('2 batch')->assertSuccessful();

    foreach ([[$this->schoolA, $old], [$this->schoolB, $stuck]] as [$school, $batch]) {
        $this->useSchool($school);
        expect(DB::table('import_batches')->where('id', $batch->id)->exists())->toBeFalse()
            ->and(DB::table('import_rows')->where('batch_id', $batch->id)->exists())->toBeFalse();
        Storage::disk('local')->assertMissing($batch->storage_path);
    }
});

it('mempertahankan batch yang masih dalam masa simpan dan data siswa hasil commit', function () {
    $recent = seedBatch($this->schoolA, 29);
    $this->inSchool($this->schoolA, fn () => Student::query()->create(['school_id' => $this->schoolA->id, 'full_name' => 'Tetap Ada', 'student_number' => '1', 'status' => 'active']));
    seedBatch($this->schoolA, 40, 'committed');

    $this->artisan('imports:purge')->assertSuccessful();

    $this->useSchool($this->schoolA);
    expect(DB::table('import_batches')->count())->toBe(1)
        ->and(DB::table('import_batches')->value('id'))->toBe($recent->id)
        ->and(DB::table('students')->where('full_name', 'Tetap Ada')->exists())->toBeTrue();
    Storage::disk('local')->assertExists($recent->storage_path);
});

it('opsi --days mengubah masa simpan dan file yang sudah hilang tidak menggagalkan penghapusan', function () {
    $batch = seedBatch($this->schoolA, 10);
    Storage::disk('local')->delete($batch->storage_path);

    $this->artisan('imports:purge --days=7')->expectsOutputToContain('1 batch')->assertSuccessful();

    $this->useSchool($this->schoolA);
    expect(DB::table('import_batches')->count())->toBe(0);
});

it('terdaftar di scheduler harian', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('imports:purge')->assertSuccessful();
});
