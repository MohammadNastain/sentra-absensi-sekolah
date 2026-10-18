import { expect, test, type Page } from "@playwright/test";

// Admin sekolah demo dari DemoSeeder (khusus dev/test): tahun ajaran 2026/2027 aktif, kelas 7A/7B/8A.
const ADMIN = { email: "admin@demo.test", password: "demo-password-123" };
const SCHOOL = "/schools/demo-smpn-1";

async function loginAsAdmin(page: Page) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(ADMIN.email);
  await page.getByLabel("Password", { exact: true }).fill(ADMIN.password);
  await page.getByRole("button", { name: "Masuk" }).click();
  await expect(page).toHaveURL(new RegExp(`${SCHOOL}$`));
}

test("Tahap 2C: admin mengimpor siswa dari CSV, meninjau baris gagal, lalu menyimpan yang valid", async ({ page }) => {
  const run = Date.now().toString(36);
  const good1 = `Impor Satu ${run}`;
  const good2 = `Impor Dua ${run}`;
  const csv = [
    "nomor_induk,nisn,nama_lengkap,tanggal_lahir,kelas",
    `IMP1-${run},,${good1},2012-05-01,7A`,
    `IMP2-${run},,${good2},,`,
    `,,Tanpa Nomor ${run},,`, // gagal: nomor induk kosong
    `IMP3-${run},123,NISN Salah ${run},,`, // gagal: NISN bukan 10 digit
  ].join("\n");

  await loginAsAdmin(page);
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Siswa" }).click();
  await page.getByRole("link", { name: "Impor dari file" }).click();
  await expect(page.getByRole("heading", { name: "Impor siswa" })).toBeVisible();

  await test.step("Unggah dan tinjau hasil validasi", async () => {
    await page.getByLabel("File impor").setInputFiles({ name: `siswa-${run}.csv`, mimeType: "text/csv", buffer: Buffer.from(csv) });
    await page.getByRole("button", { name: "Unggah dan periksa" }).click();

    const panel = page.getByRole("region", { name: "Hasil impor" });
    await expect(panel.getByRole("status")).toHaveText("Siap ditinjau", { timeout: 30_000 });
    await expect(panel).toContainText("4 baris: 2 valid dan 2 gagal");

    // Pratinjau awal menampilkan baris gagal beserta alasannya.
    await expect(panel.getByRole("row", { name: /Tanpa Nomor/ })).toContainText("Nomor induk wajib diisi");
    await expect(panel.getByRole("row", { name: /NISN Salah/ })).toContainText("NISN harus 10 digit");
    await expect(panel.getByRole("link", { name: "Unduh baris gagal untuk diperbaiki" })).toBeVisible();
  });

  await test.step("Simpan baris valid saja", async () => {
    await page.getByRole("button", { name: "Simpan 2 siswa valid" }).click();
    const panel = page.getByRole("region", { name: "Hasil impor" });
    await expect(panel.getByRole("status")).toHaveText("Selesai disimpan");
    await expect(panel).toContainText("2 siswa berhasil disimpan; 2 baris tidak disimpan");
  });

  await test.step("Siswa hasil impor muncul di daftar dengan kelasnya; baris gagal tidak", async () => {
    await page.getByRole("link", { name: "Lihat daftar siswa" }).click();
    await page.getByLabel("Cari").fill(run);
    await page.getByRole("button", { name: "Cari" }).click();
    await expect(page.getByRole("row", { name: new RegExp(good1) })).toContainText("7A");
    await expect(page.getByRole("row", { name: new RegExp(good2) })).toContainText("Belum ada kelas");
    await expect(page.getByRole("row", { name: /Tanpa Nomor|NISN Salah/ })).toHaveCount(0);
  });
});
