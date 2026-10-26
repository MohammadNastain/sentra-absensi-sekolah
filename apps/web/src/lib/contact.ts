/** Kontak WhatsApp (format internasional tanpa +) dan tampilannya. */
export const WA_NUMBER = "6282231277648";
export const WA_DISPLAY = "0822-3127-7648";

/** Tautan WhatsApp dengan pesan awal terisi. */
export function waLink(message: string): string {
  return `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(message)}`;
}

/** Tombol "Coba demo" hanya tampil bila lingkungan menyiapkan sekolah demo (nilai ini ikut terbakar saat build). */
export const DEMO_ENABLED = process.env.NEXT_PUBLIC_DEMO_ENABLED === "true";
