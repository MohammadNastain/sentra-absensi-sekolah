<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

// Tahap 2E — wali kelas hanya melihat kelas yang ia ampu dan siswanya

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $this->homeroom, [Role::HomeroomTeacher]);

    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $teacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active',
    ])->id);
    $this->otherTeacher = $otherTeacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Guru Lain', 'status' => 'active',
    ])->id);

    $makeClass = fn (string $name, string $teacher) => $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7,
        'homeroom_teacher_id' => $teacher, 'status' => 'active',
    ])->id);
    $this->ownClass = $makeClass('7A', $teacherId);
    $this->otherClass = $makeClass('7B', $otherTeacherId);

    $enroll = fn (string $name, string $number, string $class) => $this->inSchool($this->school, function () use ($name, $number, $class) {
        $student = Student::query()->create(['school_id' => $this->school->id, 'full_name' => $name, 'student_number' => $number, 'status' => 'active']);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $student->id, 'class_id' => $class, 'start_date' => '2026-07-13']);

        return $student->id;
    });
    $this->ownStudent = $enroll('Siswa Sendiri', '1', $this->ownClass);
    $this->otherStudent = $enroll('Siswa Lain', '2', $this->otherClass);
});

function asHomeroom(string $path, string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->homeroom)->{$method}("/api/v1/schools/sekolah-a{$path}", $data);
}

it('wali kelas hanya melihat kelas yang diampunya', function () {
    asHomeroom('/classes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '7A');
    asHomeroom("/classes/{$this->ownClass}")->assertOk();
    asHomeroom("/classes/{$this->otherClass}")->assertNotFound();
});

it('wali kelas hanya melihat siswa kelasnya, termasuk lewat filter dan detail', function () {
    asHomeroom('/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Siswa Sendiri');
    asHomeroom("/students?class_id={$this->otherClass}")->assertOk()->assertJsonCount(0, 'data');
    asHomeroom('/students?q=Lain')->assertOk()->assertJsonCount(0, 'data');
    asHomeroom("/students/{$this->ownStudent}")->assertOk()->assertJsonPath('data.full_name', 'Siswa Sendiri');
    asHomeroom("/students/{$this->otherStudent}")->assertNotFound();
});

it('siswa yang sudah pindah dari kelas wali tidak lagi terlihat', function () {
    $this->inSchool($this->school, fn () => ClassEnrollment::query()->where('student_id', $this->ownStudent)->update(['end_date' => '2026-07-14']));

    asHomeroom('/students')->assertOk()->assertJsonCount(0, 'data');
    asHomeroom("/students/{$this->ownStudent}")->assertNotFound();
});

it('kalender dan jadwal: kelas lain ditolak, jadwal sekolah tetap terlihat', function () {
    asHomeroom('/calendar?month=2026-08')->assertOk();
    asHomeroom("/calendar?month=2026-08&class_id={$this->ownClass}")->assertOk();
    asHomeroom("/calendar?month=2026-08&class_id={$this->otherClass}")->assertNotFound();
    asHomeroom('/schedules')->assertOk();
    asHomeroom('/holidays')->assertOk();
});

it('data lintas kelas tertutup: hanya guru sendiri yang terlihat; aksi tulis dan impor ditolak', function () {
    asHomeroom('/teachers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Wali');
    asHomeroom("/teachers/{$this->otherTeacher}")->assertNotFound();
    asHomeroom('/academic-years')->assertOk();
    asHomeroom('/students', 'postJson', ['student_number' => '9', 'full_name' => 'X'])->assertForbidden();
    asHomeroom('/classes', 'postJson', ['name' => 'X'])->assertForbidden();
    asHomeroom('/imports')->assertForbidden();
    asHomeroom('/members')->assertForbidden();
});

it('wali kelas yang juga admin tidak dibatasi', function () {
    $this->homeroom = User::factory()->create();
    $this->addMember($this->school, $this->homeroom, [Role::HomeroomTeacher, Role::SchoolAdmin]);

    asHomeroom('/classes')->assertOk()->assertJsonCount(2, 'data');
    asHomeroom('/students')->assertOk()->assertJsonCount(2, 'data');
});

it('guru nonaktif atau tanpa tautan akun tidak membuka kelas apa pun', function () {
    $this->inSchool($this->school, fn () => Teacher::query()->where('full_name', 'Wali')->update(['status' => 'inactive']));

    asHomeroom('/classes')->assertOk()->assertJsonCount(0, 'data');
    asHomeroom('/students')->assertOk()->assertJsonCount(0, 'data');
});
