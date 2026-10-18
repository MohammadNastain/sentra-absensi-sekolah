import { expect, test, type Page } from "@playwright/test";

// Admin sekolah demo dari DemoSeeder (khusus dev/test).
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

test("Tahap 3A: admin menerbitkan, menerbitkan ulang, memblokir, dan mencabut kartu QR siswa", async ({ page }) => {
  await loginAsAdmin(page);
  await nav(page, "Siswa").click();

  await test.step("Terbitkan kartu: QR tampil sekali dan bisa dicetak", async () => {
    await row(page, "Candra Wijaya").getByRole("button", { name: "Kartu QR" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: /Terbitkan (ulang )?kartu/ }).click();
    await expect(dialog.getByText("hanya tampil sekarang")).toBeVisible();
    await expect(dialog.getByRole("img", { name: "QR Candra Wijaya" })).toBeVisible();
    await dialog.getByRole("button", { name: "Selesai" }).click();
    // Setelah ditutup, tidak ada cara menampilkan QR yang sama lagi: hanya riwayat status.
    await expect(dialog.getByRole("img")).toHaveCount(0);
    await expect(dialog.getByText(/^Aktif · diterbitkan/)).toHaveCount(1);
  });

  await test.step("Terbit ulang mencabut kartu lama", async () => {
    const dialog = page.getByRole("dialog");
    // Riwayat bertambah tiap run; tunggu jumlah "Dicabut" naik agar tidak lolos pada daftar basi.
    const revoked = dialog.getByText(/^Dicabut · diterbitkan/);
    const before = await revoked.count();
    await dialog.getByRole("button", { name: "Terbitkan ulang kartu" }).click();
    await dialog.getByRole("button", { name: "Selesai" }).click();
    await expect(dialog.getByText(/^Aktif · diterbitkan/)).toHaveCount(1);
    await expect(revoked).toHaveCount(before + 1);
  });

  await test.step("Blokir (alasan wajib), buka blokir, lalu cabut", async () => {
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Blokir" }).click();
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByRole("alert")).toContainText("Alasan");
    await dialog.getByLabel("Alasan").fill("Kartu hilang di sekolah");
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByText(/^Diblokir · diterbitkan/)).toHaveCount(1);

    await dialog.getByRole("button", { name: "Buka blokir" }).click();
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByText(/^Aktif · diterbitkan/)).toHaveCount(1);

    await dialog.getByRole("button", { name: "Cabut", exact: true }).first().click();
    await dialog.getByLabel("Alasan").fill("Kartu rusak parah");
    await dialog.getByRole("button", { name: "Simpan" }).click();
    await expect(dialog.getByText(/^Aktif · diterbitkan/)).toHaveCount(0);
    await expect(dialog.getByText("Siswa belum memiliki kartu aktif.")).toBeVisible();
  });
});

test("Tahap 3A: cetak kartu massal satu kelas", async ({ page }) => {
  await loginAsAdmin(page);
  await nav(page, "Kelas").click();
  await row(page, "7A").getByRole("button", { name: "Cetak kartu QR" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Siswa yang dicetak").selectOption("all");
  await dialog.getByRole("button", { name: "Terbitkan kartu" }).click();
  await expect(dialog.getByText(/kartu diterbitkan/)).toBeVisible();
  await expect(dialog.getByRole("img", { name: "QR Ahmad Rizky" })).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Cetak" })).toBeVisible();
});
