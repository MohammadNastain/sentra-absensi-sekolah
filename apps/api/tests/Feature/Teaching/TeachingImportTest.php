<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Jadwal mengajar — impor guru (dengan mapel dan email) dan impor jadwal mengajar

beforeEach(function () {
    Storage::fake('local');
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->inSchool($this->school, function () {
        $year = AcademicYear::query()->create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26', 'is_active' => true]);
        foreach (['7A', '7B'] as $name) {
            SchoolClass::query()->create(['school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7]);
        }
    });
});

function uploadTo(string $endpoint, string $content, string $name = 'berkas.csv')
{
    $t = test();

    return $t->actingAs($t->admin)->post("/api/v1/schools/sekolah-a/imports/{$endpoint}", [
        'file' => UploadedFile::fake()->createWithContent($name, $content),
    ], ['Accept' => 'application/json']);
}

function commitImport(string $id)
{
    $t = test();

    return $t->actingAs($t->admin)->postJson("/api/v1/schools/sekolah-a/imports/{$id}/commit");
}

const TEACHER_HEADER = "nomor_pegawai,nama_lengkap,mapel,email\n";
const SCHEDULE_HEADER = "hari,jam_mulai,jam_selesai,kelas,mapel,guru\n";

it('impor guru: validasi, preview, lalu commit membuat guru, mapel baru, dan tautan mapel', function () {
    $this->inSchool($this->school, fn () => Subject::query()->create(['school_id' => $this->school->id, 'name' => 'Matematika']));

    $csv = TEACHER_HEADER
        ."198001,Siti Rahma,matematika; IPA,siti@sekolah.test\n"   // mapel Matematika sudah ada (beda huruf)
        .",Budi Santoso,Bahasa Indonesia,\n"
        .",,Tanpa Nama,\n"                                        // nama kosong
        ."198001,Nomor Kembar,,\n"                                // nomor kembar dalam file
        .",Email Salah,,bukan-email\n";

    $id = uploadTo('teachers', $csv)->assertStatus(202)
        ->assertJsonPath('data.type', 'teachers')
        ->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.total_rows', 5)
        ->assertJsonPath('data.valid_rows', 2)
        ->json('data.id');
    expect(Teacher::query()->count())->toBe(0);

    commitImport($id)->assertOk()->assertJsonPath('data.committed_rows', 2);

    $this->useSchool($this->school);
    $siti = Teacher::query()->where('employee_number', '198001')->with('subjects')->firstOrFail();
    expect($siti->email)->toBe('siti@sekolah.test')
        ->and($siti->subjects->pluck('name')->sort()->values()->all())->toBe(['IPA', 'Matematika'])
        ->and(Subject::query()->count())->toBe(3) // Matematika tidak digandakan; IPA dan Bahasa Indonesia baru
        ->and(Teacher::query()->where('full_name', 'Budi Santoso')->firstOrFail()->employee_number)->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'school.import.committed')->count())->toBe(1);
});

it('impor guru: nomor pegawai yang sudah ada ditolak, templat dapat diunduh dan langsung valid', function () {
    $this->inSchool($this->school, fn () => Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Lama', 'employee_number' => '198001012005012001']));

    $template = $this->actingAs($this->admin)->get('/api/v1/schools/sekolah-a/imports/template?type=teachers')->assertOk()->streamedContent();
    expect($template)->toContain('nomor_pegawai')->toContain('Siti Rahma');

    // Templat memakai nomor yang sama dengan guru "Lama": baris itu ditolak, baris kedua lolos.
    uploadTo('teachers', ltrim($template, "\xEF\xBB\xBF"))
        ->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.invalid_rows', 1);
    $this->actingAs($this->admin)->get('/api/v1/schools/sekolah-a/imports/template?type=lainnya')->assertStatus(422);
});

it('impor jadwal: mencocokkan guru lewat nomor pegawai atau nama, membuat mapel baru, dan menyimpan slot', function () {
    $this->inSchool($this->school, function () {
        Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Siti Rahma', 'employee_number' => '198001']);
        Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Budi Santoso']);
    });

    $csv = SCHEDULE_HEADER
        ."Senin,07:00,08:20,7A,Matematika,198001\n"
        ."senin,8.20,09:40,7a,Bahasa Indonesia,budi santoso\n"
        ."2,07:00,08:20,7B,Matematika,198001\n";

    $id = uploadTo('teaching-schedule', $csv)->assertStatus(202)
        ->assertJsonPath('data.type', 'teaching_schedule')
        ->assertJsonPath('data.valid_rows', 3)->json('data.id');
    commitImport($id)->assertOk()->assertJsonPath('data.committed_rows', 3);

    $this->useSchool($this->school);
    expect(TeachingSlot::query()->count())->toBe(3)
        ->and(Subject::query()->count())->toBe(2)
        ->and(TeachingSlot::query()->where('day_of_week', 2)->firstOrFail()->start_time)->toStartWith('07:00');
});

it('impor jadwal: menolak hari, jam, kelas, guru tidak dikenal serta bentrok di file dan dengan jadwal yang ada', function () {
    $this->inSchool($this->school, function () {
        $siti = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Siti Rahma', 'employee_number' => '198001'])->id;
        Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Kembar']);
        Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Kembar']);
        $class = SchoolClass::query()->where('name', '7B')->firstOrFail()->id;
        $subject = Subject::query()->create(['school_id' => $this->school->id, 'name' => 'IPA'])->id;
        TeachingSlot::query()->create(['school_id' => $this->school->id, 'class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $siti, 'day_of_week' => 3, 'start_time' => '10:00', 'end_time' => '11:00']);
    });

    $csv = SCHEDULE_HEADER
        ."Senin,07:00,08:20,7A,Matematika,198001\n"     // 2: valid
        ."Senin,07:30,08:00,7B,Matematika,198001\n"     // 3: guru sama, jam beririsan dengan baris 2
        ."Senin,07:30,08:00,7A,IPA,198001\n"            // 4: kelas sama beririsan dengan baris 2 (dan guru)
        ."Hari Libur,07:00,08:20,7A,Matematika,198001\n" // 5: hari salah
        ."Selasa,25:00,08:20,7A,Matematika,198001\n"    // 6: jam salah
        ."Selasa,09:00,08:00,7A,Matematika,198001\n"    // 7: selesai sebelum mulai
        ."Selasa,07:00,08:20,9Z,Matematika,198001\n"    // 8: kelas tidak ada
        ."Selasa,07:00,08:20,7A,Matematika,999\n"       // 9: guru tidak ada
        ."Selasa,07:00,08:20,7A,Matematika,Kembar\n"    // 10: nama ambigu
        ."Rabu,10:30,11:30,7A,Matematika,198001\n"      // 11: guru sudah mengajar 10:00-11:00 (jadwal yang ada)
        ."Rabu,11:00,12:00,7A,Matematika,198001\n";     // 12: valid (berurutan)

    $id = uploadTo('teaching-schedule', $csv)->assertJsonPath('data.total_rows', 11)->assertJsonPath('data.valid_rows', 2)->json('data.id');

    $rows = $this->actingAs($this->admin)->getJson("/api/v1/schools/sekolah-a/imports/{$id}/rows?status=invalid")->assertJsonCount(9, 'data');
    $fieldsByRow = collect($rows->json('data'))->mapWithKeys(fn ($r) => [$r['row_number'] => $r['errors'][0]['field']]);
    expect($fieldsByRow->all())->toBe([3 => 'guru', 4 => 'guru', 5 => 'hari', 6 => 'jam_mulai', 7 => 'jam_selesai', 8 => 'kelas', 9 => 'guru', 10 => 'guru', 11 => 'guru']);

    $this->actingAs($this->admin)->get("/api/v1/schools/sekolah-a/imports/{$id}/failed-rows")->assertOk();
});

it('impor guru dan jadwal memakai alur dan izin yang tepat: pengguna tanpa izin ditolak, kolom wajib diperiksa', function () {
    $viewer = User::factory()->create();
    $this->addMember($this->school, $viewer, [Role::HomeroomTeacher]);
    $this->actingAs($viewer)->post('/api/v1/schools/sekolah-a/imports/teachers', [
        'file' => UploadedFile::fake()->createWithContent('g.csv', TEACHER_HEADER."1,Guru,,\n"),
    ], ['Accept' => 'application/json'])->assertForbidden();

    uploadTo('teaching-schedule', "hari,kelas\nSenin,7A\n")
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_message', fn ($m) => str_contains($m, 'jam_mulai') && str_contains($m, 'guru'));
});
