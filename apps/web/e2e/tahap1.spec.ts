import { expect, test, type Browser, type Page } from "@playwright/test";
import { invitationTokenFor } from "./helpers/mailpit";
import { totp } from "./helpers/totp";

// Akun dan kunci TOTP dari DemoSeeder (khusus dev/test; seeder menolak production).
const SUPER_ADMIN = { email: "superadmin@demo.test", password: "demo-password-123", totpSecret: "JBSWY3DPEHPK3PXP" };
const NEW_PASSWORD = "kata-sandi-e2e-123";

/** Konteks browser terpisah = cookie sesi terpisah, seperti tiga orang di tiga perangkat. */
async function newSession(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  return context.newPage();
}

async function acceptInvitation(page: Page, email: string, name: string) {
  const token = await invitationTokenFor(email);
  await page.goto(`/invitations/${token}`);
  await expect(page.getByRole("heading", { name: /Bergabung dengan/ })).toBeVisible();
  await page.getByLabel("Nama lengkap").fill(name);
  await page.getByLabel("Password", { exact: true }).fill(NEW_PASSWORD);
  await page.getByLabel("Ulangi password").fill(NEW_PASSWORD);
  await page.getByRole("button", { name: "Buat akun dan terima undangan" }).click();
  await expect(page).toHaveURL(/\/login\?invited=1/);
}

async function login(page: Page, email: string, password: string) {
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Masuk" }).click();
}

test("Tahap 1: Super Admin → sekolah → admin → anggota; isolasi antar sekolah", async ({ browser }) => {
  const run = Date.now().toString(36);
  const schoolCode = `e2e-${run}`;
  const schoolName = `SMP E2E ${run}`;
  const adminEmail = `admin-${run}@e2e.test`;
  const teacherEmail = `guru-${run}@e2e.test`;

  // 1. Super Admin: login + 2FA, buat sekolah, undang Admin Sekolah pertama.
  const superAdmin = await newSession(browser);
  await test.step("Super Admin login dengan 2FA", async () => {
    await superAdmin.goto("/login");
    await login(superAdmin, SUPER_ADMIN.email, SUPER_ADMIN.password);
    await expect(superAdmin).toHaveURL(/\/two-factor/);
    await superAdmin.getByLabel("Kode verifikasi").fill(totp(SUPER_ADMIN.totpSecret));
    await superAdmin.getByRole("button", { name: "Verifikasi" }).click();
    await expect(superAdmin).toHaveURL(/\/platform$/);
  });

  await test.step("Super Admin membuat sekolah", async () => {
    await superAdmin.getByRole("main").getByRole("link", { name: "Sekolah baru" }).click();
    await superAdmin.getByLabel("Kode sekolah").fill(schoolCode);
    await superAdmin.getByLabel("Nama sekolah").fill(schoolName);
    await superAdmin.getByLabel("SMP", { exact: true }).check();
    await superAdmin.getByRole("button", { name: "Buat sekolah" }).click();
    await expect(superAdmin).toHaveURL(new RegExp(`/platform/${schoolCode}$`));
    await expect(superAdmin.getByRole("heading", { name: schoolName })).toBeVisible();
  });

  await test.step("Super Admin mengundang Admin Sekolah pertama", async () => {
    await superAdmin.getByLabel("Email").fill(adminEmail);
    await superAdmin.getByRole("button", { name: "Kirim undangan" }).click();
    await expect(superAdmin.getByText(`Undangan untuk ${adminEmail} dibuat`)).toBeVisible();
  });

  // 2. Admin: terima undangan lewat email (Mailpit), login, undang anggota kedua.
  const admin = await newSession(browser);
  await test.step("Admin menerima undangan dan login", async () => {
    await acceptInvitation(admin, adminEmail, "Admin E2E");
    await login(admin, adminEmail, NEW_PASSWORD);
    await expect(admin).toHaveURL(new RegExp(`/schools/${schoolCode}$`));
    const nav = admin.getByRole("navigation", { name: "Navigasi utama" });
    await expect(nav.getByRole("link", { name: "Anggota" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Undangan" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Audit" })).toBeVisible();
  });

  await test.step("Admin mengundang anggota kedua (Wali Kelas)", async () => {
    await admin.getByRole("link", { name: "Undangan" }).click();
    await admin.getByLabel("Email").fill(teacherEmail);
    await admin.getByLabel("Wali Kelas").check();
    await admin.getByRole("button", { name: "Kirim undangan" }).click();
    await expect(admin.getByText(`Undangan untuk ${teacherEmail} dibuat`)).toBeVisible();
  });

  // 3. Anggota kedua: izin terbatas dan tidak bisa membuka sekolah lain lewat URL.
  const teacher = await newSession(browser);
  await test.step("Anggota kedua menerima undangan dan login", async () => {
    await acceptInvitation(teacher, teacherEmail, "Guru E2E");
    await login(teacher, teacherEmail, NEW_PASSWORD);
    await expect(teacher).toHaveURL(new RegExp(`/schools/${schoolCode}$`));
  });

  await test.step("Anggota kedua hanya melihat menu sesuai izinnya", async () => {
    const nav = teacher.getByRole("navigation", { name: "Navigasi utama" });
    await expect(nav.getByRole("link", { name: "Ringkasan" })).toBeVisible();
    await expect(nav.getByRole("link", { name: "Anggota" })).toHaveCount(0);
    await expect(nav.getByRole("link", { name: "Undangan" })).toHaveCount(0);
    await expect(nav.getByRole("link", { name: "Audit" })).toHaveCount(0);
  });

  await test.step("Anggota kedua tidak bisa membuka sekolah lain lewat URL", async () => {
    await teacher.goto("/schools/demo-smpn-1");
    await expect(teacher.getByRole("heading", { name: "Sekolah tidak dapat dibuka" })).toBeVisible();
    await expect(teacher.getByText("Data tidak ditemukan.")).toBeVisible();

    // Halaman milik admin sekolah sendiri pun ditolak API karena izin, bukan sekadar disembunyikan.
    await teacher.goto(`/schools/${schoolCode}/audit`);
    await expect(teacher.getByText("Anda tidak memiliki izin untuk tindakan ini.")).toBeVisible();
  });

  // 4. Jejak audit: perubahan penting tercatat dan terlihat oleh admin sekolah itu saja.
  await test.step("Admin melihat audit undangan dan penerimaan", async () => {
    await admin.goto(`/schools/${schoolCode}/audit`);
    await expect(admin.getByText("school.invitation.created").first()).toBeVisible();
    await expect(admin.getByText("school.member.joined").first()).toBeVisible();
  });

  await Promise.all([superAdmin, admin, teacher].map((page) => page.context().close()));
});
