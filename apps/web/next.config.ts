import path from "node:path";
import type { NextConfig } from "next";

/** Asal API Laravel. Browser selalu memanggil same-origin; Next meneruskannya (cookie sesi tidak lintas situs). */
const apiOrigin = process.env.API_ORIGIN ?? "http://localhost:8000";

const isProd = process.env.NODE_ENV === "production";

// CSP produksi (berbasis nonce) dipasang per request oleh src/proxy.ts; header statis lain ada di bawah.

const common = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  ...(isProd
    ? [
        { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
      ]
    : []),
];

// Build image produksi (infra/docker/prod/web.Dockerfile) memakai keluaran standalone; dev dan CI tidak berubah.
const standalone = process.env.NEXT_OUTPUT === "standalone";

const nextConfig: NextConfig = {
  ...(standalone ? { output: "standalone" as const, outputFileTracingRoot: path.join(import.meta.dirname, "../..") } : {}),
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
