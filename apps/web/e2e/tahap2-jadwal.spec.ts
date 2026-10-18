import { expect, test, type Page } from "@playwright/test";

// Admin sekolah demo dari DemoSeeder (khusus dev/test): tahun ajaran 2026/2027 aktif, jadwal sekolah Senin–Jumat.
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

function nav(page: Page, name: string) {
  return page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name, exact: true });
}

test("Tahap 2D: admin menerbitkan versi jadwal, mengelola libur, dan melihat kalender", async ({ page }) => {
  const run = Date.now().toString(36);
  const className = `JD-${run}`;
  const holidayName = `Libur E2E ${run}`;

  await loginAsAdmin(page);

  await test.step("Menu jadwal muncul dan jadwal sekolah demo tampil", async () => {
    for (const name of ["Jadwal", "Libur", "Kalender"]) await expect(nav(page, name)).toBeVisible();
    await nav(page, "Jadwal").click();
    await expect(row(page, "Seluruh sekolah")).toContainText("Sen, Sel, Rab, Kam, Jum");
  });

  await test.step("Buat kelas khusus untuk jadwal per kelas", async () => {
    await nav(page, "Kelas").click();
    await page.getByRole("button", { name: "Tambah kelas" }).click();
    await expect(page.getByRole("dialog").getByLabel("Tahun ajaran")).toHaveValue(/.+/); // daftar tahun ajaran selesai dimuat
    await page.getByLabel("Nama kelas").fill(className);
    await page.getByRole("dialog").getByLabel("Tingkat").selectOption("9");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, className)).toBeVisible();
  });

  await test.step("Terbitkan versi jadwal kelas, lalu versi baru yang menutup versi lama", async () => {
    await nav(page, "Jadwal").click();
    await page.getByRole("button", { name: "Terbitkan versi jadwal" }).click();
    let dialog = page.getByRole("dialog");
    await dialog.getByLabel("Cakupan").selectOption("class");
    await dialog.getByLabel("Kelas", { exact: true }).selectOption({ label: `${className} (2026/2027)` });
    await dialog.getByLabel("Rabu").uncheck();
    await dialog.getByLabel("Kamis").uncheck();
    await dialog.getByLabel("Jumat").uncheck();
    await dialog.getByLabel("Batas tepat waktu").fill("07:15");
    await dialog.getByLabel("Berlaku mulai").fill("2026-08-03");
    await dialog.getByRole("button", { name: "Terbitkan" }).click();
    await expect(row(page, `Kelas ${className}`)).toContainText("Sen, Sel");
    await expect(row(page, `Kelas ${className}`)).toContainText("06:00–07:15");

    await page.getByRole("button", { name: "Terbitkan versi jadwal" }).click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Cakupan").selectOption("class");
    await dialog.getByLabel("Kelas", { exact: true }).selectOption({ label: `${className} (2026/2027)` });
    await dialog.getByLabel("Batas tepat waktu").fill("07:45");
    await dialog.getByLabel("Berlaku mulai").fill("2026-09-07");
    // Hanya Senin–Jumat default: Rabu–Jumat belum punya versi sebelumnya, Senin–Selasa menutup versi lama.
    await dialog.getByRole("button", { name: "Terbitkan" }).click();

    const versions = page.getByRole("row", { name: new RegExp(`Kelas ${className}`) });
    await expect(versions).toHaveCount(2);
    await expect(versions.filter({ hasText: "06:00–07:15" })).toContainText("Sen, Sel"); // versi lama berakhir sehari sebelumnya
    await expect(versions.filter({ hasText: "06:00–07:15" })).toContainText("6 Sep 2026");
    await expect(versions.filter({ hasText: "06:00–07:45" })).toContainText("Sen, Sel, Rab, Kam, Jum");
    await expect(versions.filter({ hasText: "06:00–07:45" })).toContainText("sekarang");

    // Tanggal berlaku yang tidak lebih baru dari versi terakhir ditolak.
    await page.getByRole("button", { name: "Terbitkan versi jadwal" }).click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Cakupan").selectOption("class");
    await dialog.getByLabel("Kelas", { exact: true }).selectOption({ label: `${className} (2026/2027)` });
    await dialog.getByLabel("Berlaku mulai").fill("2026-08-10");
    await dialog.getByRole("button", { name: "Terbitkan" }).click();
    await expect(dialog.getByRole("alert")).toContainText("Jadwal bentrok");
    await dialog.getByRole("button", { name: "Batal" }).click();
  });

  await test.step("Hari libur: tambah, ubah, hapus", async () => {
    await nav(page, "Libur").click();
    await page.getByRole("button", { name: "Tambah hari libur" }).click();
    await page.getByLabel("Nama", { exact: true }).fill(holidayName);
    await page.getByLabel("Tanggal mulai").fill("2099-03-01");
    await page.getByLabel("Tanggal selesai").fill("2099-02-01");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(page.getByRole("dialog").getByRole("alert")).toContainText("tidak boleh sebelum");

    await page.getByLabel("Tanggal selesai").fill("2099-03-03");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, holidayName)).toContainText("1 Mar 2099");

    await row(page, holidayName).getByRole("button", { name: "Ubah" }).click();
    await page.getByLabel("Tanggal selesai").fill("2099-03-02");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, holidayName)).toContainText("2 Mar 2099");

    await row(page, holidayName).getByRole("button", { name: "Hapus" }).click();
    await page.getByRole("dialog").getByRole("button", { name: "Hapus" }).click();
    await expect(row(page, holidayName)).toHaveCount(0);
  });

  await test.step("Kalender menampilkan jadwal kelas sesuai versi yang berlaku", async () => {
    await nav(page, "Kalender").click();
    await expect(page.getByRole("heading", { level: 2 })).toBeVisible();
    await page.getByLabel("Tampilkan jadwal untuk").selectOption({ label: `Kelas ${className}` });
    // Bulan berjalan (≥ Sep 2026) memakai versi terbaru kelas ini.
    await expect(page.getByText("Masuk 06:00–07:45").first()).toBeVisible();
    await page.getByLabel("Tampilkan jadwal untuk").selectOption({ label: "Seluruh sekolah" });
    await expect(page.getByText("Masuk 06:00–07:00").first()).toBeVisible();
  });
});
