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

function row(page: Page, text: string) {
  return page.getByRole("row", { name: new RegExp(text) });
}

test("Tahap 2B: admin menambah siswa, menempatkan, memindahkan, dan meluluskan", async ({ page }) => {
  const run = Date.now().toString(36);
  const name = `Siswa E2E ${run}`;
  const number = `E2E-${run}`;
  const nisn = String(Date.now()).slice(-10);

  await loginAsAdmin(page);
  await page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name: "Siswa" }).click();
  await expect(page.getByRole("heading", { name: "Siswa" })).toBeVisible();
  await expect(row(page, "Ahmad Rizky")).toContainText("7A");

  await test.step("Tambah siswa, lalu tolak nomor induk yang sama", async () => {
    await page.getByRole("button", { name: "Tambah siswa" }).click();
    await page.getByLabel("Nama lengkap").fill(name);
    await page.getByLabel("Nomor induk").fill(number);
    await page.getByLabel("NISN").fill(nisn);
    await page.getByRole("button", { name: "Simpan" }).click();

    await page.getByLabel("Cari").fill(run);
    await page.getByRole("button", { name: "Cari" }).click();
    await expect(row(page, name)).toContainText("Belum ada kelas");

    await page.getByRole("button", { name: "Tambah siswa" }).click();
    await page.getByLabel("Nama lengkap").fill(`${name} dobel`);
    await page.getByLabel("Nomor induk").fill(number);
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(page.getByRole("dialog").getByRole("alert")).toContainText("sudah dipakai");
    await page.getByRole("button", { name: "Batal" }).click();
  });

  await test.step("Tempatkan di 7A, pindahkan ke 7B, tolak penempatan ke kelas yang sama", async () => {
    await row(page, name).getByRole("button", { name: "Kelas" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Kelas tujuan").selectOption({ label: "7A (2026/2027)" });
    await dialog.getByLabel("Mulai tanggal").fill("2026-08-03");
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByRole("listitem")).toHaveCount(1);
    await expect(dialog.getByRole("listitem").first()).toContainText("7A");

    await dialog.getByLabel("Kelas tujuan").selectOption({ label: "7B (2026/2027)" });
    await dialog.getByLabel("Mulai tanggal").fill("2026-09-01");
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByRole("listitem")).toHaveCount(2);
    await expect(dialog.getByRole("listitem").nth(0)).toContainText("7A");
    await expect(dialog.getByRole("listitem").nth(1)).toContainText("7B");
    await expect(dialog.getByRole("listitem").nth(1)).toContainText("sekarang");

    await dialog.getByLabel("Kelas tujuan").selectOption({ label: "7B (2026/2027)" });
    await dialog.getByLabel("Mulai tanggal").fill("2026-09-15");
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByRole("alert")).toContainText("sudah berada di kelas ini");
    await dialog.getByRole("button", { name: "Tutup" }).click();
  });

  await test.step("Luluskan siswa: kelas ditutup dan tidak bisa ditempatkan lagi", async () => {
    await row(page, name).getByRole("button", { name: "Ubah" }).click();
    await page.getByRole("dialog").getByLabel("Status").selectOption("graduated");
    await page.getByRole("button", { name: "Simpan" }).click();
    await expect(row(page, name)).toContainText("Lulus");

    await row(page, name).getByRole("button", { name: "Kelas" }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog.getByRole("listitem")).toHaveCount(2);
    await expect(dialog.getByLabel("Kelas tujuan")).toHaveCount(0);
  });
});
