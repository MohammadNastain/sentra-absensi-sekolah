import type { NextConfig } from "next";

/** Asal API Laravel. Browser selalu memanggil same-origin; Next meneruskannya (cookie sesi tidak lintas situs). */
const apiOrigin = process.env.API_ORIGIN ?? "http://localhost:8000";

const isProd = process.env.NODE_ENV === "production";

/**
 * CSP dashboard dan terminal. Skrip inline Next (hidrasi) membutuhkan 'unsafe-inline' sampai CSP berbasis nonce
 * dipasang; sisanya ketat: tanpa objek/plugin, tanpa bingkai, form dan koneksi hanya same-origin. Pengembangan
 * memerlukan 'unsafe-eval' dan websocket HMR sehingga CSP hanya dipasang di produksi.
 */
const csp = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob:",
  "media-src 'self' blob:",
  "font-src 'self' data:",
  "connect-src 'self'",
  "worker-src 'self' blob:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const common = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  ...(isProd
    ? [
        { key: "Content-Security-Policy", value: csp },
        { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
      ]
    : []),
];

const nextConfig: NextConfig = {
  transpilePackages: ["@sentra/api-client"],
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${apiOrigin}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${apiOrigin}/sanctum/:path*` },
    ];
  },
  async headers() {
    return [
      // Kamera hanya untuk terminal (pemindai QR); halaman lain menolaknya.
      { source: "/terminal/:path*", headers: [...common, { key: "Permissions-Policy", value: "camera=(self), microphone=(), geolocation=()" }] },
      { source: "/terminal", headers: [...common, { key: "Permissions-Policy", value: "camera=(self), microphone=(), geolocation=()" }] },
      { source: "/((?!terminal).*)", headers: [...common, { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" }] },
    ];
  },
};

export default nextConfig;
