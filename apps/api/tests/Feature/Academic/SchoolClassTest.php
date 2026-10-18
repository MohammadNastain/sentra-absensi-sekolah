<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Tahap 2A — kelas (rombel) dan penugasan wali kelas

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26',
    ]));
    $this->teacher = $this->inSchool($this->school, fn () => Teacher::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Siti Rahma', 'status' => 'active',
    ]));
});

function classesApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/classes{$path}", $data);
}

function seedClass(string $name, int $grade, ?string $teacherId = null, ?string $yearId = null, string $status = 'active'): string
{
    $t = test();

    return $t->inSchool($t->school, fn () => SchoolClass::query()->create([
        'school_id' => $t->school->id, 'academic_year_id' => $yearId ?? $t->year->id, 'name' => $name,
        'grade_level' => $grade, 'homeroom_teacher_id' => $teacherId, 'status' => $status,
    ])->id);
}

it('membuat kelas dengan wali kelas dan mencatat audit', function () {
    $id = classesApi('', 'postJson', [
        'academic_year_id' => $this->year->id, 'name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => $this->teacher->id,
    ])->assertCreated()
        ->assertJsonPath('data.name', '7A')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.academic_year.name', '2026/2027')
        ->assertJsonPath('data.homeroom_teacher.full_name', 'Siti Rahma')
        ->json('data.id');

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.class.created')->where('entity_id', $id)->exists())->toBeTrue();
});

it('wali kelas boleh kosong, dan dapat dipasang lalu dilepas', function () {
    $id = classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => '7B', 'grade_level' => 7])
        ->assertCreated()->assertJsonPath('data.homeroom_teacher', null)->json('data.id');

    classesApi("/{$id}", 'patchJson', ['homeroom_teacher_id' => $this->teacher->id])->assertOk()->assertJsonPath('data.homeroom_teacher.id', $this->teacher->id);
    classesApi("/{$id}", 'patchJson', ['homeroom_teacher_id' => null])->assertOk()->assertJsonPath('data.homeroom_teacher', null);
});

it('validasi: field wajib, rentang tingkat, tahun ajaran dan status tidak bisa diubah/diisi sembarang', function () {
    classesApi('', 'postJson', [])->assertJsonValidationErrors(['academic_year_id', 'name', 'grade_level']);
    classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => 'X', 'grade_level' => 14])->assertJsonValidationErrors(['grade_level']);
    classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => 'X', 'grade_level' => 7, 'status' => 'archived'])->assertJsonValidationErrors(['status']);

    $id = seedClass('7A', 7);
    classesApi("/{$id}", 'patchJson', ['academic_year_id' => $this->year->id])->assertJsonValidationErrors(['academic_year_id']);
    classesApi("/{$id}", 'patchJson', ['status' => 'dihapus'])->assertJsonValidationErrors(['status']);
});

it('nama kelas unik per tahun ajaran; nama yang sama di tahun lain diperbolehkan', function () {
    seedClass('7A', 7);
    $nextYear = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => '2027/2028', 'start_date' => '2027-07-12', 'end_date' => '2028-06-24',
    ])->id);

    classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => '7A', 'grade_level' => 7])->assertJsonValidationErrors(['name']);
    classesApi('', 'postJson', ['academic_year_id' => $nextYear, 'name' => '7A', 'grade_level' => 7])->assertCreated();

    $b = seedClass('7B', 7);
    classesApi("/{$b}", 'patchJson', ['name' => '7A'])->assertJsonValidationErrors(['name']);
    classesApi("/{$b}", 'patchJson', ['name' => '7B'])->assertOk(); // nama sendiri tidak dianggap bentrok
});

it('menolak tahun ajaran dan wali kelas milik sekolah lain, atau guru nonaktif', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreignYear = $this->inSchool($other, fn () => AcademicYear::query()->create([
        'school_id' => $other->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26',
    ])->id);
    $foreignTeacher = $this->inSchool($other, fn () => Teacher::query()->create(['school_id' => $other->id, 'full_name' => 'Asing', 'status' => 'active'])->id);
    $inactive = $this->inSchool($this->school, fn () => Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Pensiun', 'status' => 'inactive'])->id);

    classesApi('', 'postJson', ['academic_year_id' => $foreignYear, 'name' => '7A', 'grade_level' => 7])->assertJsonValidationErrors(['academic_year_id']);
    foreach ([$foreignTeacher, $inactive] as $teacherId) {
        classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => $teacherId])
            ->assertJsonValidationErrors(['homeroom_teacher_id']);
    }
});

it('daftar: filter tahun ajaran, tingkat, status; urut tingkat lalu nama', function () {
    seedClass('8A', 8);
    seedClass('7B', 7);
    seedClass('7A', 7);
    seedClass('9A', 9, status: 'archived');

    classesApi()->assertJsonPath('meta.total', 4)
        ->assertJsonPath('data.0.name', '7A')->assertJsonPath('data.1.name', '7B')->assertJsonPath('data.2.name', '8A');
    classesApi('?grade_level=7')->assertJsonCount(2, 'data');
    classesApi('?status=archived')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '9A');
    classesApi("?academic_year_id={$this->year->id}")->assertJsonCount(4, 'data');
    classesApi('?academic_year_id=bukan-uuid')->assertOk()->assertJsonCount(4, 'data'); // filter tak valid diabaikan, bukan error DB
});

it('mengarsipkan kelas dan mencatat audit before/after', function () {
    $id = seedClass('7A', 7);

    classesApi("/{$id}", 'patchJson', ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.class.updated')->where('entity_id', $id)->first();
    expect(json_decode($audit->before_value, true)['status'])->toBe('active')
        ->and(json_decode($audit->after_value, true)['status'])->toBe('archived');
});

it('T05: kelas sekolah lain → 404 dan tidak ikut terdaftar', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $otherYear = $this->inSchool($other, fn () => AcademicYear::query()->create([
        'school_id' => $other->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26',
    ])->id);
    $foreign = $this->inSchool($other, fn () => SchoolClass::query()->create([
        'school_id' => $other->id, 'academic_year_id' => $otherYear, 'name' => '7A', 'grade_level' => 7,
    ])->id);

    classesApi("/{$foreign}")->assertNotFound();
    classesApi("/{$foreign}", 'patchJson', ['name' => 'Dibajak'])->assertNotFound();
    classesApi()->assertJsonPath('meta.total', 0);
    classesApi('/bukan-uuid')->assertNotFound();
});

it('ID yang bukan UUID di body → 422 (bukan error database)', function () {
    classesApi('', 'postJson', ['academic_year_id' => 'bukan-uuid', 'name' => '7A', 'grade_level' => 7])->assertJsonValidationErrors(['academic_year_id']);
    classesApi('', 'postJson', ['academic_year_id' => $this->year->id, 'name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => 'bukan-uuid'])
        ->assertJsonValidationErrors(['homeroom_teacher_id']);

    $id = seedClass('7C', 7);
    classesApi("/{$id}", 'patchJson', ['homeroom_teacher_id' => 'bukan-uuid'])->assertJsonValidationErrors(['homeroom_teacher_id']);
});
