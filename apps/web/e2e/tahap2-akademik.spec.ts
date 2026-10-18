import { expect, test, type Page } from "@playwright/test";

// Admin sekolah demo dari DemoSeeder (khusus dev/test). Sekolah demo sudah punya tahun ajaran aktif 2026/2027.
const ADMIN = { email: "admin@demo.test", password: "demo-password-123" };
const SCHOOL = "/schools/demo-smpn-1";

async function loginAsAdmin(page: Page) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(ADMIN.email);
  await page.getByLabel("Password", { exact: true }).fill(ADMIN.password);
  await page.getByRole("button", { name: "Masuk" }).click();
  await expect(page).toHaveURL(new RegExp(`${SCHOOL}$`));
}

function row(page: Page, text: string) {
  return page.getByRole("row", { name: new RegExp(text) });
}

test("Tahap 2A: admin menyiapkan tahun ajaran, guru, dan kelas dengan wali kelas", async ({ page }) => {
  const run = Date.now().toString(36);
  // Tahun jauh di depan dan acak agar tidak bentrok dengan tahun ajaran lain maupun run sebelumnya.
  const farYear = 3000 + Math.floor(Math.random() * 6000);
  const yearName = `E2E ${run}`;
  const teacherName = `Guru E2E ${run}`;
  const className = `E2E-${run}`;

  await loginAsAdmin(page);

  await test.step("Menu akademik muncul untuk admin", async () => {
    const nav = page.getByRole("navigation", { name: "Navigasi utama" });
    for (const name of ["Tahun ajaran", "Kelas", "Guru"]) {
      await expect(nav.getByRole("link", { name })).toBeVisible();
    }
  });

  await test.step("Tahun ajaran: buat, tolak yang bentrok, ubah, dan aktifkan", async () => {
    await page.getByRole("link", { name: "Tahun ajaran" }).click();
    await expect(page.getByRole("heading", { name: "Tahun ajaran" })).toBeVisible();
    await expect(row(page, "2026/2027")).toContainText("Aktif");

    await page.getByRole("button", { name: "Tahun ajaran baru" }).click();
    await page.getByLabel("Nama", { exact: true }).fill(yearName);
    await page.getByLabel("Tanggal mulai").fill(`${farYear}-07-01`);
    await page.getByLabel("Tanggal selesai").fill(`${farYear}-12-31`);
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, yearName)).toContainText("Tidak aktif");

    // Rentang yang beririsan dengan 2026/2027 ditolak dengan pesan jelas.
    await page.getByRole("button", { name: "Tahun ajaran baru" }).click();
    await page.getByLabel("Nama", { exact: true }).fill(`Bentrok ${run}`);
    await page.getByLabel("Tanggal mulai").fill("2026-08-01");
    await page.getByLabel("Tanggal selesai").fill("2026-09-01");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(page.getByRole("dialog").getByRole("alert")).toContainText("tumpang tindih");
    await page.getByRole("button", { name: "Batal" }).click();

    // Ubah tanggal selesai.
    await row(page, yearName).getByRole("button", { name: "Ubah" }).click();
    await page.getByLabel("Tanggal selesai").fill(`${farYear}-11-30`);
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, yearName)).toContainText("30");

    // Aktifkan lalu kembalikan tahun ajaran demo agar data dev tidak berubah.
    await row(page, yearName).getByRole("button", { name: "Aktifkan" }).click();
    await page.getByRole("button", { name: "Aktifkan", exact: true }).last().click();
    await expect(row(page, yearName)).toContainText("Aktif");
    await expect(row(page, "2026/2027")).toContainText("Tidak aktif");

    await row(page, "2026/2027").getByRole("button", { name: "Aktifkan" }).click();
    await page.getByRole("button", { name: "Aktifkan", exact: true }).last().click();
    await expect(row(page, "2026/2027")).toContainText("Aktif");
  });

  await test.step("Guru: tambah dan cari", async () => {
    await page.getByRole("link", { name: "Guru", exact: true }).click();
    await page.getByRole("button", { name: "Tambah guru" }).click();
    await page.getByLabel("Nama lengkap").fill(teacherName);
    await page.getByLabel("Nomor pegawai").fill(`E2E${run}`);
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, teacherName)).toContainText("Tanpa akun");

    await page.getByLabel("Cari").fill(run);
    await page.getByRole("button", { name: "Cari" }).click();
    await expect(page.getByRole("row")).toHaveCount(2); // header + satu hasil
  });

  await test.step("Kelas: buat dengan wali kelas, lalu guru itu tidak bisa dinonaktifkan", async () => {
    await page.getByRole("link", { name: "Kelas", exact: true }).click();
    await page.getByRole("button", { name: "Tambah kelas" }).click();
    await page.getByLabel("Nama kelas").fill(className);
    await page.getByRole("dialog").getByLabel("Tingkat").selectOption("9");
    await page.getByLabel("Wali kelas (opsional)").selectOption({ label: teacherName });
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, className)).toContainText(teacherName);
    await expect(row(page, className)).toContainText("2026/2027");

    await page.getByRole("link", { name: "Guru", exact: true }).click();
    await page.getByLabel("Cari").fill(run);
    await page.getByRole("button", { name: "Cari" }).click();
    await row(page, teacherName).getByRole("button", { name: "Nonaktifkan" }).click();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("wali kelas");

    // Arsipkan kelasnya, baru guru boleh dinonaktifkan.
    await page.getByRole("link", { name: "Kelas", exact: true }).click();
    await row(page, className).getByRole("button", { name: "Arsipkan" }).click();
    await expect(row(page, className)).toContainText("Diarsipkan");

    await page.getByRole("link", { name: "Guru", exact: true }).click();
    await page.getByLabel("Cari").fill(run);
    await page.getByRole("button", { name: "Cari" }).click();
    await row(page, teacherName).getByRole("button", { name: "Nonaktifkan" }).click();
    await expect(row(page, teacherName)).toContainText("Nonaktif");
  });
});
