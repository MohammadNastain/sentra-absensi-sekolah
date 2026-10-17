import type { NextConfig } from "next";

/** Asal API Laravel. Browser selalu memanggil same-origin; Next meneruskannya (cookie sesi tidak lintas situs). */
const apiOrigin = process.env.API_ORIGIN ?? "http://localhost:8000";

const nextConfig: NextConfig = {
  transpilePackages: ["@sentra/api-client"],
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${apiOrigin}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${apiOrigin}/sanctum/:path*` },
    ];
  },
};

export default nextConfig;
