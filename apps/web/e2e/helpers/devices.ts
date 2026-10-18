import { expect, type Page } from "@playwright/test";

function nav(page: Page, name: string) {
  return page.getByRole("navigation", { name: "Navigasi utama" }).getByRole("link", { name, exact: true });
}

/** Sekolah demo dibatasi 1 perangkat aktif; cabut semuanya sebelum mendaftarkan perangkat uji. */
export async function revokeAllDevices(page: Page) {
  await nav(page, "Perangkat").click();
  await expect(page.getByRole("heading", { name: "Perangkat terminal" })).toBeVisible();
  await expect(page.getByText("Memuat…")).toBeHidden();
  for (let guard = 0; guard < 5; guard++) {
    const cabut = page.getByRole("button", { name: "Cabut", exact: true }).first();
    if ((await cabut.count()) === 0) return;
    const revoked = page.getByText("Dicabut", { exact: true });
    const before = await revoked.count();
    await cabut.click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Alasan").fill("Pembersihan uji E2E");
    await dialog.getByRole("button", { name: "Cabut", exact: true }).click();
    await expect(dialog).toBeHidden();
    // Tunggu daftar disegarkan sebelum mengklik lagi; baris basi masih punya tombol Cabut.
    await expect(revoked).toHaveCount(before + 1);
  }
}
