"use client";

import dynamic from "next/dynamic";

// Three.js hanya dimuat di klien dan sesudah halaman tampil, supaya formulir login tidak tertahan.
const Logo3D = dynamic(() => import("@/components/Logo3D"), { ssr: false });

/** Panggung logo 3D di panel merek halaman autentikasi. */
export function LogoStage({ className }: { className?: string }) {
  return <Logo3D className={className} />;
}
