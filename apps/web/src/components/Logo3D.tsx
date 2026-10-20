"use client";

import { Canvas, useFrame, useLoader } from "@react-three/fiber";
import { Suspense, useEffect, useMemo, useRef, useState } from "react";
import * as THREE from "three";

// Lambang dipotong dari logo (540x616 px); satu unit dunia = 308 px, jadi tinggi lambang = 2 unit.
const PX = 2 / 616;
const HEX_R = 296 * PX;
const DEPTH = 0.22;

function hexShape(radius: number): THREE.Shape {
  const shape = new THREE.Shape();
  for (let i = 0; i < 6; i++) {
    const a = THREE.MathUtils.degToRad(-90 + i * 60);
    const x = radius * Math.cos(a);
    const y = radius * Math.sin(a);
    if (i === 0) shape.moveTo(x, y);
    else shape.lineTo(x, y);
  }
  shape.closePath();

  return shape;
}

/** Lambang berketebalan: wajah memakai gambar logo, sisi tepi biru metalik. */
function Emblem() {
  const loaded = useLoader(THREE.TextureLoader, "/brand/emblem.png");
  const geometry = useMemo(() => {
    const bevel = 0.045;
    const g = new THREE.ExtrudeGeometry(hexShape(HEX_R - bevel), {
      depth: DEPTH,
      bevelEnabled: true,
      bevelSize: bevel,
      bevelThickness: 0.05,
      bevelSegments: 4,
    });
    g.translate(0, 0, -DEPTH / 2);

    return g;
  }, []);

  const map = useMemo(() => {
    const texture = loaded.clone();
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 8;
    // UV ExtrudeGeometry = koordinat x,y mentah; petakan ke gambar yang berpusat di (0,0).
    texture.repeat.set(1 / (540 * PX), 1 / (616 * PX));
    texture.offset.set(0.5, 0.5);
    texture.needsUpdate = true;

    return texture;
  }, [loaded]);

  return (
    <mesh geometry={geometry}>
      <meshStandardMaterial attach="material-0" map={map} emissiveMap={map} emissive="#ffffff" emissiveIntensity={0.42} roughness={0.35} metalness={0.1} />
      <meshStandardMaterial attach="material-1" color="#2f6bff" emissive="#1b46c9" emissiveIntensity={0.5} roughness={0.25} metalness={0.8} />
    </mesh>
  );
}

function Ring({ radius, tilt, spin, color, orbs }: { radius: number; tilt: [number, number, number]; spin: number; color: string; orbs: number }) {
  const group = useRef<THREE.Group>(null);
  useFrame((_, delta) => {
    if (group.current) group.current.rotation.z += delta * spin;
  });

  return (
    <group rotation={tilt}>
      <group ref={group}>
        <mesh>
          <torusGeometry args={[radius, 0.016, 16, 160]} />
          <meshStandardMaterial color={color} emissive={color} emissiveIntensity={1.4} roughness={0.3} />
        </mesh>
        {Array.from({ length: orbs }, (_, i) => {
          const a = (i / orbs) * Math.PI * 2 + 0.6;

          return (
            <mesh key={i} position={[Math.cos(a) * radius, Math.sin(a) * radius, 0]}>
              <sphereGeometry args={[0.085, 32, 32]} />
              <meshStandardMaterial color="#9fd4ff" emissive="#5fb4ff" emissiveIntensity={1.2} roughness={0.2} />
            </mesh>
          );
        })}
      </group>
    </group>
  );
}

/** Berlian kecil melayang di sekitar lambang. */
function Sparks() {
  const group = useRef<THREE.Group>(null);
  const items = useMemo(
    () => [
      [-1.55, 1.0, 0.2, 0.07],
      [1.5, 1.2, -0.1, 0.06],
      [1.7, -0.7, 0.3, 0.075],
      [-1.4, -1.15, 0.1, 0.06],
      [0.2, 1.75, 0, 0.05],
    ],
    [],
  );
  useFrame((state) => {
    if (!group.current) return;
    group.current.children.forEach((child, i) => {
      child.position.y = items[i][1] + Math.sin(state.clock.elapsedTime * 0.9 + i * 1.7) * 0.08;
      child.rotation.y += 0.01;
    });
  });

  return (
    <group ref={group}>
      {items.map(([x, y, z, s], i) => (
        <mesh key={i} position={[x, y, z]} scale={s * 2.2}>
          <octahedronGeometry args={[1, 0]} />
          <meshStandardMaterial color={i % 2 ? "#38bdf8" : "#fbbf24"} emissive={i % 2 ? "#0ea5e9" : "#f59e0b"} emissiveIntensity={1.1} roughness={0.2} />
        </mesh>
      ))}
    </group>
  );
}

function Scene({ still }: { still: boolean }) {
  const rig = useRef<THREE.Group>(null);
  useFrame((state) => {
    if (!rig.current || still) return;
    const t = state.clock.elapsedTime;
    // Berayun pelan dan mengambang; kursor menambah kemiringan.
    const yaw = Math.sin(t * 0.6) * 0.55 + state.pointer.x * 0.45;
    const pitch = Math.sin(t * 0.45) * 0.12 - state.pointer.y * 0.3;
    rig.current.rotation.y = THREE.MathUtils.lerp(rig.current.rotation.y, yaw, 0.06);
    rig.current.rotation.x = THREE.MathUtils.lerp(rig.current.rotation.x, pitch, 0.06);
    rig.current.position.y = Math.sin(t * 0.9) * 0.06;
  });

  return (
    <>
      <ambientLight intensity={0.9} />
      <directionalLight position={[3, 4, 5]} intensity={2.2} color="#ffffff" />
      <pointLight position={[-3, -2, 3]} intensity={18} color="#3b82f6" />
      <pointLight position={[3, -1, -2]} intensity={10} color="#fbbf24" />
      <group ref={rig} rotation={[0, still ? -0.3 : 0, 0]}>
        <Emblem />
        <Ring radius={1.62} tilt={[1.15, 0.25, -0.35]} spin={0.55} color="#3d8bff" orbs={1} />
        <Ring radius={1.42} tilt={[-1.0, 0.5, 0.5]} spin={-0.4} color="#7dd3fc" orbs={1} />
        <Sparks />
      </group>
    </>
  );
}

function supportsWebGL(): boolean {
  try {
    const canvas = document.createElement("canvas");

    return Boolean(canvas.getContext("webgl2") ?? canvas.getContext("webgl"));
  } catch {
    return false;
  }
}

/** Logo Sentra 3D. Tanpa WebGL atau saat pengguna meminta gerak dikurangi, tampil gambar statis / diam. */
export default function Logo3D({ className }: { className?: string }) {
  const [mode, setMode] = useState<"pending" | "webgl" | "image">("pending");
  const [still, setStill] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- deteksi kemampuan browser hanya mungkin di klien
    setMode(supportsWebGL() ? "webgl" : "image");
    setStill(window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }, []);

  if (mode === "webgl") {
    return (
      <div className={className} aria-label="Logo Sentra" role="img">
        <Canvas camera={{ position: [0, 0, 4.7], fov: 38 }} dpr={[1, 2]} gl={{ alpha: true, antialias: true }} frameloop={still ? "demand" : "always"}>
          <Suspense fallback={null}>
            <Scene still={still} />
          </Suspense>
        </Canvas>
      </div>
    );
  }

  return (
    <div className={className}>
      {/* eslint-disable-next-line @next/next/no-img-element -- aset statis lokal */}
      {mode === "image" ? <img src="/brand/emblem.png" alt="Logo Sentra" className="mx-auto h-full object-contain" /> : null}
    </div>
  );
}
