import type { Metadata } from "next";
import { headers } from "next/headers";
import { connection } from "next/server";
import { THEME_INIT_SCRIPT } from "@/components/ThemeToggle";
import { Geist_Mono, Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: { default: "Sentra Sekolah", template: "%s · Sentra Sekolah" },
  description: "Platform manajemen sekolah multi-tenant.",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  // CSP berbasis nonce (src/proxy.ts) mewajibkan render dinamis: nonce baru di setiap request.
  await connection();
  const nonce = (await headers()).get("x-nonce") ?? undefined;

  return (
    <html lang="id" suppressHydrationWarning className={`${inter.variable} ${geistMono.variable} h-full antialiased`}>
      <head>
        <script nonce={nonce} dangerouslySetInnerHTML={{ __html: THEME_INIT_SCRIPT }} />
      </head>
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
