import type { NavItem } from "@/components/AppShell";
import type { PermissionCode } from "@/app/schools/[code]/school-context";
import {
  IconBook,
  IconBuilding,
  IconCalendar,
  IconChart,
  IconCheck,
  IconClock,
  IconDevice,
  IconEdit,
  IconGrad,
  IconHome,
  IconMail,
  IconShield,
  IconSun,
  IconUserPlus,
  IconUsers,
} from "@/components/icons";

/**
 * Menu sidebar sebuah sekolah, disaring menurut izin (`can`). Dipakai layout sekolah dan sidebar Super Admin
 * (yang memegang semua izin Admin Sekolah, D-21) agar keduanya selalu sama. `has` menyaring menurut fitur yang efektif bagi
 * sekolah (ADR 0009); hanya UX, API tetap menegakkan entitlement. Bawaan: semua fitur dianggap ada.
 */
export function buildSchoolNav(
  code: string,
  pathname: string,
  can: (permission: PermissionCode) => boolean,
  groupPrefix = "",
  has: (feature: string) => boolean = () => true,
): NavItem[] {
  const base = `/schools/${code}`;
  const on = (path: string) => pathname.startsWith(`${base}/${path}`);
  const group = (name: string) => `${groupPrefix}${name}`;
  const nav: NavItem[] = [{ href: base, label: "Ringkasan", icon: IconHome, group: group("Utama"), active: pathname === base }];

  if (has("attendance") && (can("school.attendance.assist") || can("school.attendance.review"))) {
    nav.push({ href: `${base}/attendance`, label: "Kehadiran", icon: IconCheck, group: group("Kehadiran"), active: on("attendance") });
  }
  if (has("attendance") && (can("school.devices.view") || can("school.attendance.assist"))) {
    // Terminal pemindai QR/RFID untuk murid (layar penuh, dipasangkan lewat menu Perangkat).
    nav.push({ href: "/terminal", label: "Terminal scan", icon: IconDevice, group: group("Kehadiran"), active: false });
  }
  if (has("attendance.corrections") && can("school.attendance.correction_request")) {
    nav.push({ href: `${base}/corrections`, label: "Koreksi", icon: IconEdit, group: group("Kehadiran"), active: on("corrections") });
  }
  if (has("attendance.leave") && can("school.leave.manage")) {
    nav.push({ href: `${base}/leave`, label: "Izin", icon: IconMail, group: group("Kehadiran"), active: on("leave") });
  }
  if (has("attendance.reports") && can("school.reports.export")) {
    nav.push({ href: `${base}/reports`, label: "Laporan", icon: IconChart, group: group("Kehadiran"), active: on("reports") });
  }
  if (can("school.academic.view")) {
    if (has("academic.years")) {
      nav.push({ href: `${base}/academic-years`, label: "Tahun ajaran", icon: IconBook, group: group("Akademik"), active: on("academic-years") });
    }
    if (has("academic.classes")) {
      nav.push({ href: `${base}/classes`, label: "Kelas", icon: IconBuilding, group: group("Akademik"), active: on("classes") });
    }
    if (has("teachers")) {
      nav.push({ href: `${base}/teachers`, label: "Guru", icon: IconGrad, group: group("Akademik"), active: on("teachers") });
    }
    // Mata pelajaran dibuka dari halaman ini; jadwal mengajar sendiri mensyaratkan mata pelajaran.
    if (has("academic.teaching_schedule")) {
      nav.push({ href: `${base}/teaching`, label: "Jadwal mengajar", icon: IconClock, group: group("Akademik"), active: on("teaching") });
    }
  }
  if (has("students") && can("school.students.view")) {
    nav.push({ href: `${base}/students`, label: "Siswa", icon: IconUsers, group: group("Akademik"), active: on("students") });
  }
  // Pembelajaran (LMS, ADR 0010): guru dan admin mengelola; tampilan siswa menyusul di tahap berikutnya.
  if (can("school.lms.manage")) {
    if (has("lms.materials")) {
      nav.push({ href: `${base}/lms/materials`, label: "Materi", icon: IconBook, group: group("Pembelajaran"), active: on("lms/materials") });
    }
    if (has("lms.assignments")) {
      nav.push({ href: `${base}/lms/assignments`, label: "Tugas", icon: IconEdit, group: group("Pembelajaran"), active: on("lms/assignments") });
    }
  }
  if (has("attendance") && can("school.schedule.view")) {
    nav.push({ href: `${base}/schedules`, label: "Jadwal", icon: IconClock, group: group("Jadwal"), active: on("schedules") });
    nav.push({ href: `${base}/holidays`, label: "Libur", icon: IconSun, group: group("Jadwal"), active: on("holidays") });
    nav.push({ href: `${base}/calendar`, label: "Kalender", icon: IconCalendar, group: group("Jadwal"), active: on("calendar") });
  }
  if (can("school.members.view")) {
    nav.push({ href: `${base}/members`, label: "Anggota", icon: IconUsers, group: group("Administrasi"), active: on("members") });
  }
  if (can("school.members.manage")) {
    nav.push({ href: `${base}/invitations`, label: "Undangan", icon: IconUserPlus, group: group("Administrasi"), active: on("invitations") });
  }
  if (has("attendance") && can("school.devices.view")) {
    nav.push({ href: `${base}/devices`, label: "Perangkat", icon: IconDevice, group: group("Administrasi"), active: on("devices") });
  }
  if (can("school.audit.view")) {
    nav.push({ href: `${base}/audit`, label: "Audit", icon: IconShield, group: group("Administrasi"), active: on("audit") });
  }

  return nav;
}
