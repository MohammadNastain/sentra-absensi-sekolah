<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

// Tahap 2C — impor siswa: unggah → validasi async (sync di test) → preview → commit baris valid saja (D-15)

beforeEach(function () {
    Storage::fake('local');
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26', 'is_active' => true,
    ]));
    $this->class7a = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'name' => '7A', 'grade_level' => 7,
    ])->id);
});

function importApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/imports{$path}", $data);
}

function uploadCsv(string $content, string $name = 'siswa.csv')
{
    $t = test();

    return $t->actingAs($t->admin)->post('/api/v1/schools/sekolah-a/imports/students', [
        'file' => UploadedFile::fake()->createWithContent($name, $content),
    ], ['Accept' => 'application/json']);
}

const CSV_HEADER = "nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas\n";

it('mengunggah CSV, memvalidasi, dan menampilkan preview baris valid dan gagal', function () {
    $csv = CSV_HEADER
        ."1001,0012345678,Ahmad Rizky,2012-05-01,7A\n"
        ."1002,,Bunga Lestari,,\n"
        .",,Tanpa Nomor,,\n"            // nomor induk kosong
        ."1001,,Duplikat Dalam File,,\n" // duplikat dengan baris 2
        ."1005,123,NISN Salah,,\n"
        ."1006,,Kelas Hantu,,9Z\n"
        ."1007,,Lahir Besok,2999-01-01,\n";

    $id = uploadCsv($csv)->assertStatus(202)
        ->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.total_rows', 7)
        ->assertJsonPath('data.valid_rows', 2)
        ->assertJsonPath('data.invalid_rows', 5)
        ->json('data.id');

    importApi("/{$id}/rows?status=invalid")->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.row_number', 4)
        ->assertJsonPath('data.0.errors.0.field', 'nomor_induk');
    importApi("/{$id}/rows?status=valid")->assertJsonCount(2, 'data');
    importApi("/{$id}")->assertJsonPath('data.original_filename', 'siswa.csv');
    expect(Student::query()->count())->toBe(0); // validasi tidak menyimpan siswa
});

it('commit menyimpan baris valid saja, menempatkan kelas, dan mencatat satu audit', function () {
    $id = uploadCsv(CSV_HEADER."1001,0012345678,Ahmad Rizky,01/05/2012,7a\n1002,,Bunga Lestari,,\n,,Gagal,,\n")->json('data.id');

    importApi("/{$id}/commit", 'postJson')->assertOk()
        ->assertJsonPath('data.status', 'committed')
        ->assertJsonPath('data.committed_rows', 2)
        ->assertJsonPath('data.invalid_rows', 1);

    $this->useSchool($this->school);
    $ahmad = Student::query()->where('student_number', '1001')->with('enrollments')->firstOrFail();
    expect($ahmad->date_of_birth?->toDateString())->toBe('2012-05-01')
        ->and($ahmad->enrollments)->toHaveCount(1)
        ->and($ahmad->enrollments[0]->class_id)->toBe($this->class7a)
        ->and($ahmad->enrollments[0]->start_date->toDateString())->toBe('2026-07-13')
        ->and(Student::query()->count())->toBe(2);
    expect(DB::table('audit_logs')->where('action', 'school.import.committed')->count())->toBe(1);

    // Commit kedua ditolak: tidak ada penyimpanan ganda.
    importApi("/{$id}/commit", 'postJson')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    $this->useSchool($this->school);
    expect(Student::query()->count())->toBe(2);
});

it('nomor yang sudah ada di sekolah ditolak saat validasi, dan yang direbut sebelum commit dilewati', function () {
    $this->inSchool($this->school, fn () => Student::query()->create(['school_id' => $this->school->id, 'student_number' => '1001', 'full_name' => 'Lama']));

    $id = uploadCsv(CSV_HEADER."1001,,Bentrok,,\n1002,,Aman,,\n1003,,Direbut,,\n")
        ->assertJsonPath('data.valid_rows', 2)->json('data.id');

    // Sebelum commit, nomor 1003 dipakai siswa lain.
    $this->inSchool($this->school, fn () => Student::query()->create(['school_id' => $this->school->id, 'student_number' => '1003', 'full_name' => 'Penyerobot']));

    importApi("/{$id}/commit", 'postJson')->assertOk()
        ->assertJsonPath('data.committed_rows', 1)
        ->assertJsonPath('data.invalid_rows', 2);
    importApi("/{$id}/rows?status=skipped")->assertJsonCount(1, 'data')->assertJsonPath('data.0.values.nomor_induk', '1003');
});

it('mengunduh baris gagal sebagai file perbaikan dengan kolom kesalahan dan tanpa injeksi rumus', function () {
    $id = uploadCsv(CSV_HEADER.",,=SUM(1),,\n,,Tanpa Nomor,,\n1003,,Valid,,\n")->json('data.id');

    $body = $this->actingAs($this->admin)->get("/api/v1/schools/sekolah-a/imports/{$id}/failed-rows")
        ->assertOk()->streamedContent();

    expect($body)->toContain('kesalahan')->toContain('Tanpa Nomor')->not->toContain('Valid')
        ->and($body)->toContain("'=SUM(1)"); // diawali apostrof agar tidak dihitung sebagai rumus
});

it('membaca XLSX (termasuk sel angka dan tanggal) dan CSV bertitik koma', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Nomor Induk', 'NISN', 'Nama Lengkap', 'Tanggal Lahir', 'Kelas']));
    $writer->addRow(new Row([
        Cell::fromValue(2001), Cell::fromValue('0012345678'), Cell::fromValue('Dari Excel'),
        Cell::fromValue(new DateTimeImmutable('2012-05-01'), new Style(format: 'yyyy-mm-dd')), Cell::fromValue('7A'),
    ]));
    $writer->close();

    $this->actingAs($this->admin)->post('/api/v1/schools/sekolah-a/imports/students', [
        'file' => new UploadedFile($path, 'siswa.xlsx', null, null, true),
    ], ['Accept' => 'application/json'])
        ->assertStatus(202)->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.invalid_rows', 0);

    uploadCsv("nomor_induk;nama_lengkap\n3001;Titik Koma\n")->assertJsonPath('data.valid_rows', 1);
    unlink($path);
});

it('batch gagal dengan pesan jelas: kolom wajib hilang, file kosong, terlalu banyak baris', function () {
    uploadCsv("nama\nTanpa Nomor\n")->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_message', 'Kolom wajib tidak ditemukan: nomor_induk. Gunakan templat yang disediakan.');
    uploadCsv(CSV_HEADER)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_message', 'File tidak berisi baris data.');

    $rows = str_repeat("x,,Nama,,\n", 5001);
    uploadCsv(CSV_HEADER.$rows)->assertJsonPath('data.status', 'failed');
    $id = uploadCsv("nama\nx\n")->json('data.id');
    importApi("/{$id}/commit", 'postJson')->assertStatus(409); // batch gagal tidak bisa di-commit
});

it('menolak file berbahaya atau salah: bukan CSV/XLSX, ZIP menyamar sebagai CSV, XLSX palsu, terlalu besar', function () {
    $post = fn (UploadedFile $f) => $this->actingAs($this->admin)->post('/api/v1/schools/sekolah-a/imports/students', ['file' => $f], ['Accept' => 'application/json']);

    $post(UploadedFile::fake()->createWithContent('siswa.exe', 'MZ'))->assertStatus(422)->assertJsonValidationErrors(['file']);
    $post(UploadedFile::fake()->createWithContent('siswa.csv', "PK\x03\x04zipisi"))->assertStatus(422)->assertJsonValidationErrors(['file']);
    $post(UploadedFile::fake()->createWithContent('siswa.csv', "a,b\0c\n"))->assertStatus(422)->assertJsonValidationErrors(['file']);
    $post(UploadedFile::fake()->createWithContent('siswa.xlsx', 'bukan zip'))->assertStatus(422)->assertJsonValidationErrors(['file']);
    $post(UploadedFile::fake()->create('besar.csv', 6000))->assertStatus(422)->assertJsonValidationErrors(['file']);
    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/imports/students', [])->assertJsonValidationErrors(['file']);
    importApi()->assertJsonPath('meta.total', 0);
});

it('templat CSV dapat diunduh dan langsung valid bila diunggah (kelas disesuaikan)', function () {
    $template = $this->actingAs($this->admin)->get('/api/v1/schools/sekolah-a/imports/template')->assertOk()->streamedContent();

    expect($template)->toContain('nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas');
    uploadCsv(ltrim($template, "\xEF\xBB\xBF"))->assertJsonPath('data.valid_rows', 2);
});

it('T05: impor sekolah lain → 404, tidak muncul di daftar, dan file tersimpan per sekolah', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $otherAdmin = User::factory()->create();
    $this->addMember($other, $otherAdmin, [Role::SchoolAdmin]);
    $foreign = $this->actingAs($otherAdmin)->post('/api/v1/schools/sekolah-b/imports/students', [
        'file' => UploadedFile::fake()->createWithContent('b.csv', CSV_HEADER."1,,Anak B,,\n"),
    ], ['Accept' => 'application/json'])->json('data.id');

    importApi("/{$foreign}")->assertNotFound();
    importApi("/{$foreign}/rows")->assertNotFound();
    importApi("/{$foreign}/commit", 'postJson')->assertNotFound();
    importApi()->assertJsonPath('meta.total', 0);
    Storage::disk('local')->assertExists("imports/{$other->id}/{$foreign}.csv");
});
