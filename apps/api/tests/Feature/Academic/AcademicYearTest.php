<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Tahap 2A — tahun ajaran (DATABASE_DESIGN §3.2)

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function yearsApi(string $path = '', string $method = 'getJson', array $data = [], ?User $as = null)
{
    $t = test();

    return $t->actingAs($as ?? $t->admin)->{$method}("/api/v1/schools/sekolah-a/academic-years{$path}", $data);
}

function seedYear(School $school, string $name, string $start, string $end, bool $active = false): string
{
    return test()->inSchool($school, fn () => AcademicYear::query()->create([
        'school_id' => $school->id, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => $active,
    ])->id);
}

it('membuat tahun ajaran (tidak aktif) dan mencatat audit', function () {
    $id = yearsApi('', 'postJson', ['name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26'])
        ->assertCreated()
        ->assertJsonPath('data.name', '2026/2027')
        ->assertJsonPath('data.start_date', '2026-07-13')
        ->assertJsonPath('data.is_active', false)
        ->json('data.id');

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.academic_year.created')->where('entity_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and(json_decode($audit->after_value, true))->toMatchArray(['name' => '2026/2027', 'end_date' => '2027-06-26']);
});

it('daftar diurutkan dari tahun terbaru', function () {
    seedYear($this->school, '2024/2025', '2024-07-15', '2025-06-27');
    seedYear($this->school, '2025/2026', '2025-07-14', '2026-06-26');

    yearsApi()->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.name', '2025/2026')
        ->assertJsonPath('data.1.name', '2024/2025');
});

it('validasi: field wajib, format tanggal, urutan tanggal, dan is_active dilarang', function () {
    yearsApi('', 'postJson', [])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors(['name', 'start_date', 'end_date']);

    yearsApi('', 'postJson', ['name' => 'X', 'start_date' => '13-07-2026', 'end_date' => '2027-06-26'])
        ->assertJsonValidationErrors(['start_date']);

    yearsApi('', 'postJson', ['name' => 'X', 'start_date' => '2026-07-13', 'end_date' => '2026-07-13'])
        ->assertJsonValidationErrors(['end_date']);

    yearsApi('', 'postJson', ['name' => 'X', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26', 'is_active' => true])
        ->assertJsonValidationErrors(['is_active']);
});

it('nama tahun ajaran unik per sekolah, tetapi boleh sama di sekolah lain', function () {
    seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');
    $other = School::factory()->create(['code' => 'sekolah-b']);
    seedYear($other, '2026/2027', '2026-07-13', '2027-06-26'); // tanggal sama pun boleh antar sekolah

    yearsApi('', 'postJson', ['name' => '2026/2027', 'start_date' => '2028-07-10', 'end_date' => '2029-06-20'])
        ->assertJsonValidationErrors(['name']);
});

it('menolak rentang yang tumpang tindih dengan kode ACADEMIC_YEAR_OVERLAP', function () {
    seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi('', 'postJson', ['name' => 'Bentrok', 'start_date' => '2027-06-26', 'end_date' => '2027-12-31'])
        ->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_YEAR_OVERLAP');

    // Berurutan tanpa irisan diterima.
    yearsApi('', 'postJson', ['name' => '2027/2028', 'start_date' => '2027-06-27', 'end_date' => '2028-06-30'])->assertCreated();
});

it('ubah sebagian: tanggal yang tidak dikirim diambil dari data tersimpan', function () {
    $id = seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi("/{$id}", 'patchJson', ['end_date' => '2026-07-01'])->assertJsonValidationErrors(['end_date']);
    yearsApi("/{$id}", 'patchJson', ['start_date' => '2027-07-01'])->assertJsonValidationErrors(['end_date']);

    yearsApi("/{$id}", 'patchJson', ['end_date' => '2027-06-30'])->assertOk()
        ->assertJsonPath('data.start_date', '2026-07-13')
        ->assertJsonPath('data.end_date', '2027-06-30');

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.academic_year.updated')->where('entity_id', $id)->first();
    expect(json_decode($audit->before_value, true)['end_date'])->toBe('2027-06-26')
        ->and(json_decode($audit->after_value, true)['end_date'])->toBe('2027-06-30');
});

it('mengubah tanggal hingga bentrok dengan tahun lain ditolak', function () {
    $first = seedYear($this->school, '2025/2026', '2025-07-14', '2026-06-26');
    seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi("/{$first}", 'patchJson', ['end_date' => '2026-08-01'])->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_YEAR_OVERLAP');
});

it('aktivasi menjadikan satu-satunya yang aktif dan mencatat audit untuk keduanya', function () {
    $old = seedYear($this->school, '2025/2026', '2025-07-14', '2026-06-26', active: true);
    $new = seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi("/{$new}/activate", 'postJson')->assertOk()->assertJsonPath('data.is_active', true);

    $this->useSchool($this->school);
    expect(DB::table('academic_years')->where('is_active', true)->pluck('id')->all())->toBe([$new]);
    expect(DB::table('audit_logs')->where('action', 'school.academic_year.deactivated')->where('entity_id', $old)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'school.academic_year.activated')->where('entity_id', $new)->exists())->toBeTrue();
});

it('aktivasi idempoten: mengaktifkan yang sudah aktif tidak membuat audit baru', function () {
    $id = seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26', active: true);

    yearsApi("/{$id}/activate", 'postJson')->assertOk()->assertJsonPath('data.is_active', true);

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.academic_year.activated')->count())->toBe(0);
});

it('T05: tahun ajaran sekolah lain → 404 untuk semua aksi, dan tidak muncul di daftar', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = seedYear($other, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi("/{$foreign}")->assertNotFound();
    yearsApi("/{$foreign}", 'patchJson', ['name' => 'Dibajak'])->assertNotFound();
    yearsApi("/{$foreign}/activate", 'postJson')->assertNotFound();
    yearsApi()->assertOk()->assertJsonPath('meta.total', 0);

    $this->useSchool($other);
    expect(DB::table('academic_years')->where('id', $foreign)->value('name'))->toBe('2026/2027');
});

it('ID bukan UUID → 404', function () {
    yearsApi('/bukan-uuid')->assertNotFound();
    yearsApi('/bukan-uuid/activate', 'postJson')->assertNotFound();
});

it('kepala sekolah boleh melihat tetapi tidak boleh mengubah', function () {
    $principal = User::factory()->create();
    $this->addMember($this->school, $principal, [Role::Principal]);
    $id = seedYear($this->school, '2026/2027', '2026-07-13', '2027-06-26');

    yearsApi('', 'getJson', [], $principal)->assertOk();
    yearsApi('', 'postJson', ['name' => 'X', 'start_date' => '2030-07-01', 'end_date' => '2031-06-30'], $principal)->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    yearsApi("/{$id}/activate", 'postJson', [], $principal)->assertForbidden();
});

it('petugas dan operator terminal belum punya akses data akademik', function () {
    foreach ([Role::DutyOfficer, Role::TerminalOperator] as $role) {
        $user = User::factory()->create();
        $this->addMember($this->school, $user, [$role]);

        yearsApi('', 'getJson', [], $user)->assertForbidden();
    }
});
