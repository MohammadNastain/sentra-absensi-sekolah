import { expect, test, type Page } from "@playwright/test";

// Akun demo dari DemoSeeder (khusus dev/test): wali@demo.test = wali kelas 7A (guru Siti Rahma).
const PASSWORD = "demo-password-123";
const SCHOOL = "/schools/demo-smpn-1";

async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Masuk" }).click();
  await expect(page).toHaveURL(new RegExp(`${SCHOOL}$`));
}

function row(page: Page, text: string) {
  return page.getByRole("row", { name: new RegExp(text) });
}

function nav(page: Page, name: string) {
  return page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name, exact: true });
}

test("Tahap 2E: wali kelas hanya melihat kelas dan siswanya; admin mengirim tautan reset password", async ({ browser }) => {
  const waliContext = await browser.newContext();
  const wali = await waliContext.newPage();

  await test.step("Wali kelas: hanya kelas 7A dan siswanya, tanpa tombol kelola", async () => {
    await login(wali, "wali@demo.test");
    await expect(nav(wali, "Anggota")).toHaveCount(0);

    await nav(wali, "Kelas").click();
    await expect(row(wali, "7A")).toBeVisible();
    await expect(row(wali, "7B")).toHaveCount(0);
    await expect(row(wali, "8A")).toHaveCount(0);
    await expect(wali.getByRole("button", { name: "Tambah kelas" })).toHaveCount(0);

    await nav(wali, "Siswa").click();
    await expect(row(wali, "Ahmad Rizky")).toContainText("7A");
    await expect(row(wali, "Dewi Anggraini")).toHaveCount(0);
    await expect(row(wali, "Fitri Handayani")).toHaveCount(0);
    await expect(wali.getByRole("button", { name: "Tambah siswa" })).toHaveCount(0);

    await nav(wali, "Guru").click();
    await expect(row(wali, "Siti Rahma")).toBeVisible();
    await expect(row(wali, "Budi Santoso")).toHaveCount(0);

    await nav(wali, "Kalender").click();
    await expect(wali.getByLabel("Tampilkan jadwal untuk").locator("option", { hasText: "Kelas 7A" })).toHaveCount(1);
    await expect(wali.getByLabel("Tampilkan jadwal untuk").locator("option", { hasText: "Kelas 7B" })).toHaveCount(0);
  });

  await test.step("Admin mengirim tautan reset password ke wali kelas", async () => {
    const adminContext = await browser.newContext();
    const admin = await adminContext.newPage();
    await login(admin, "admin@demo.test");
    await nav(admin, "Anggota").click();

    await row(admin, "wali@demo.test").getByRole("button", { name: "Kirim reset password" }).click();
    const dialog = admin.getByRole("dialog");
    await dialog.getByLabel("Alasan").fill("Wali kelas lupa password");
    await dialog.getByRole("button", { name: "Kirim tautan" }).click();

    // Broker membatasi satu tautan per menit per akun; menjalankan ulang cepat menghasilkan peringatan, bukan pengiriman.
    await expect(admin.getByRole("status").or(dialog.getByRole("alert"))).toContainText(/Tautan reset password dikirim ke wali@demo.test|baru saja dikirim/);

    await expect(row(admin, "admin@demo.test").getByRole("button", { name: "Kirim reset password" })).toHaveCount(0);
    await adminContext.close();
  });

  await waliContext.close();
});
