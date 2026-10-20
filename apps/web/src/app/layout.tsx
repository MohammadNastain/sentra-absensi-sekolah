import type { Metadata } from "next";
import { connection } from "next/server";
import { Geist_Mono, Plus_Jakarta_Sans } from "next/font/google";
import "./globals.css";

const jakarta = Plus_Jakarta_Sans({
  variable: "--font-jakarta",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: { default: "Sentra Absensi Sekolah", template: "%s · Sentra Absensi Sekolah" },
  description: "Absensi sekolah multi-tenant.",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  // CSP berbasis nonce (src/proxy.ts) mewajibkan render dinamis: nonce baru di setiap request.
  await connection();

  return (
    <html lang="id" className={`${jakarta.variable} ${geistMono.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
