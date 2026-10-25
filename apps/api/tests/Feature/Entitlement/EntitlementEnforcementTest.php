<?php

use App\Domain\Attendance\CloseAttendanceDay;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantAwareJob;
use App\Jobs\GenerateReportExport;
use App\Jobs\ValidateImport;
use App\Models\ImportBatch;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// ADR 0009 §5 — penegakan entitlement di API, terminal, impor/ekspor, job, dan penutupan hari.

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function api(string $method, string $path, array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->json($method, "/api/v1/schools/sekolah-a{$path}", $data);
}

afterEach(fn () => Carbon::setTestNow());

it('paket Basic: absensi dan rekap boleh; guru, izin, dan koreksi ditolak dengan kode dan fitur', function () {
    setSchoolPlan($this->school->id, 'basic');

    api('GET', '/students')->assertOk();
    api('GET', '/attendance/today')->assertOk();
    api('GET', '/teachers')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED')->assertJsonPath('feature', 'teachers');
    api('GET', '/leave-requests')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED')->assertJsonPath('feature', 'attendance.leave');
    api('POST', '/attendance/corrections')->assertForbidden()->assertJsonPath('feature', 'attendance.corrections');
});

it('override grant membuka fitur di luar paket, revoke menutup fitur dari paket', function () {
    setSchoolPlan($this->school->id, 'basic');
    setFeatureOverride($this->school->id, 'teachers', 'grant');
    setFeatureOverride($this->school->id, 'attendance.reports', 'revoke');

    api('GET', '/teachers')->assertOk();
    api('GET', '/reports/summary?from=2026-10-01&to=2026-10-31')->assertForbidden()->assertJsonPath('feature', 'attendance.reports');
});

it('fitur tertahan prasyaratnya: mencabut data siswa menutup absensi walau absensi ada di paket', function () {
    setFeatureOverride($this->school->id, 'students', 'revoke');

    api('GET', '/students')->assertForbidden()->assertJsonPath('feature', 'students');
    api('GET', '/attendance/today')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
});

it('sekolah tanpa langganan tidak punya fitur apa pun, tetapi rute inti tetap terbuka', function () {
    $bare = School::factory()->create(['code' => 'sekolah-b']);
    DB::statement('SELECT 1');
    app(DatabaseContext::class)->runAsPlatform(fn () => DB::table('school_subscriptions')->where('school_id', $bare->id)->delete());
    $this->addMember($bare, $this->admin, [Role::SchoolAdmin]);

    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-b/students')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-b')->assertOk();
    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-b/members')->assertOk();
});

it('penolakan fitur mendahului penolakan izin peran', function () {
    setSchoolPlan($this->school->id, 'basic');
    $principal = User::factory()->create();
    $this->addMember($this->school, $principal, [Role::Principal]);

    // Kepala sekolah tidak punya izin kelola izin-sakit DAN sekolah tidak punya fitur: yang dilaporkan fitur.
    $this->actingAs($principal)->postJson('/api/v1/schools/sekolah-a/leave-requests', [])
        ->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
});

it('Super Admin yang mengakses sekolah juga terkena entitlement pada rute operasional (keputusan 2)', function () {
    setSchoolPlan($this->school->id, 'basic');
    $super = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());

    $this->actingAsPlatformAdminWithTwoFactor($super)->getJson('/api/v1/schools/sekolah-a/teachers')
        ->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
    $this->actingAsPlatformAdminWithTwoFactor($super)->getJson('/api/v1/schools/sekolah-a/students')->assertOk();
});

it('menonaktifkan fitur tidak menghapus data dan mengaktifkannya lagi mengembalikan akses', function () {
    $this->inSchool($this->school, fn () => Student::query()->create(['school_id' => $this->school->id, 'full_name' => 'Ahmad', 'student_number' => '1001', 'status' => 'active']));
    api('GET', '/students')->assertOk()->assertJsonPath('meta.total', 1);

    setFeatureOverride($this->school->id, 'students', 'revoke');
    api('GET', '/students')->assertForbidden();
    expect($this->inSchool($this->school, fn () => Student::query()->count()))->toBe(1);

    app(DatabaseContext::class)->runAsPlatform(fn () => DB::table('school_feature_overrides')->where('school_id', $this->school->id)->delete());
    api('GET', '/students')->assertOk()->assertJsonPath('meta.total', 1);
});

it('fitur satu sekolah tidak memengaruhi sekolah lain', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $this->addMember($other, $this->admin, [Role::SchoolAdmin]);
    setSchoolPlan($other->id, 'basic');

    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-b/teachers')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-a/teachers')->assertOk();
});

// --- Impor: gerbang per jenis ---

it('impor: riwayat, templat, dan batch hanya untuk jenis yang fiturnya dimiliki', function () {
    Storage::fake('local');
    $csv = "nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas\n1001,,Ahmad,,\n";
    $id = $this->actingAs($this->admin)->post('/api/v1/schools/sekolah-a/imports/students', ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('data.id');

    // Batch siswa terlihat selama fitur siswa ada, dan jenis lain ditolak tepat pada jenisnya.
    api('GET', '/imports')->assertOk()->assertJsonPath('data.0.id', $id);
    api('GET', '/imports/template?type=teachers')->assertOk();
    setSchoolPlan($this->school->id, 'basic'); // tanpa teachers
    api('GET', '/imports/template?type=teachers')->assertForbidden()->assertJsonPath('feature', 'teachers');
    api('GET', '/imports/template?type=students')->assertOk();
    api('POST', '/imports/teachers')->assertForbidden()->assertJsonPath('feature', 'teachers');

    // Siswa dicabut: batch lama tidak terbaca dan tidak bisa di-commit, walau Teachers diberikan (rute any-of lolos).
    setFeatureOverride($this->school->id, 'teachers', 'grant');
    setFeatureOverride($this->school->id, 'students', 'revoke');
    api('GET', "/imports/{$id}")->assertForbidden()->assertJsonPath('feature', 'students');
    api('POST', "/imports/{$id}/commit")->assertForbidden()->assertJsonPath('feature', 'students');
    expect(api('GET', '/imports')->assertOk()->json('data'))->toBe([]);
});

// --- Job ---

it('job ekspor yang diantre dibatalkan bila fitur dicabut sebelum dijalankan', function () {
    Storage::fake('local');
    Queue::fake();

    $id = api('POST', '/exports', ['report' => 'summary', 'format' => 'csv', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']])
        ->assertStatus(202)->json('data.id');
    setFeatureOverride($this->school->id, 'attendance.reports', 'revoke');

    $job = null;
    Queue::assertPushed(GenerateReportExport::class, function (GenerateReportExport $j) use (&$job) {
        $job = $j;

        return true;
    });
    $job->handle($this->dbContext());

    $row = $this->inSchool($this->school, fn () => DB::selectOne('SELECT status, file_path, error_message FROM export_jobs WHERE id = ?', [$id]));
    expect($row->status)->toBe('failed')->and($row->file_path)->toBeNull()->and($row->error_message)->toContain('paket');
    Storage::disk('local')->assertDirectoryEmpty('exports/'.$this->school->id);
});

it('job validasi impor dibatalkan dan batch ditandai gagal bila fitur jenisnya dicabut', function () {
    Storage::fake('local');
    Queue::fake();
    $csv = "nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas\n1001,,Ahmad,,\n";
    $id = $this->actingAs($this->admin)->post('/api/v1/schools/sekolah-a/imports/students', ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)], ['Accept' => 'application/json'])
        ->assertStatus(202)->json('data.id');
    setFeatureOverride($this->school->id, 'students', 'revoke');

    $job = null;
    Queue::assertPushed(ValidateImport::class, function (ValidateImport $j) use (&$job) {
        $job = $j;

        return true;
    });
    $job->handle($this->dbContext());

    $batch = $this->inSchool($this->school, fn () => ImportBatch::query()->find($id));
    expect($batch->status)->toBe(ImportBatch::STATUS_FAILED)->and($batch->error_message)->toContain('Data siswa');
});

it('job lain yang tidak digerbang (mis. undangan) tetap berjalan tanpa paket', function () {
    // Kontrak dasar: requiredFeature() null berarti tidak ada pemeriksaan entitlement.
    $job = new class($this->school->id) extends TenantAwareJob
    {
        public static bool $ran = false;

        protected function run(): void
        {
            self::$ran = true;
        }
    };
    app(DatabaseContext::class)->runAsPlatform(fn () => DB::table('school_subscriptions')->where('school_id', $this->school->id)->delete());

    $job->handle($this->dbContext());

    expect($job::$ran)->toBeTrue();
});

// --- Penutupan hari terjadwal ---

it('attendance:close-day melewati sekolah tanpa modul absensi', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    setFeatureOverride($other->id, 'attendance', 'revoke');

    $results = app(CloseAttendanceDay::class)->runForAllSchools('2026-10-19');

    expect($results)->toHaveKey('sekolah-a')->not->toHaveKey('sekolah-b');
});
