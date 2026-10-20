import type { ReactNode, SVGProps } from "react";

/** Ikon garis 24x24 (gaya konsisten, tanpa pustaka). Warna mengikuti `currentColor`. */
function Svg({ children, ...props }: SVGProps<SVGSVGElement> & { children: ReactNode }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      {children}
    </svg>
  );
}

type IconProps = SVGProps<SVGSVGElement>;

export const IconHome = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1z" />
  </Svg>
);
export const IconUsers = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="9" cy="8" r="3.2" />
    <path d="M3 19.5c.4-3.2 3-5 6-5s5.6 1.8 6 5" />
    <path d="M16 5.2a3 3 0 0 1 0 5.6M17.5 14.7c2 .5 3.2 2.2 3.5 4.8" />
  </Svg>
);
export const IconMail = (p: IconProps) => (
  <Svg {...p}>
    <rect x="3.5" y="5.5" width="17" height="13" rx="2.5" />
    <path d="m4.5 7.5 7.5 5.5 7.5-5.5" />
  </Svg>
);
export const IconCalendar = (p: IconProps) => (
  <Svg {...p}>
    <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
    <path d="M8 3v4M16 3v4M3.5 10h17" />
  </Svg>
);
export const IconBook = (p: IconProps) => (
  <Svg {...p}>
    <path d="M5 4.5h9a3 3 0 0 1 3 3V20H8a3 3 0 0 1-3-3z" />
    <path d="M17 7.5h2V20h-2M8.5 9h5M8.5 12.5h5" />
  </Svg>
);
export const IconGrad = (p: IconProps) => (
  <Svg {...p}>
    <path d="m3 9 9-4.5L21 9l-9 4.5z" />
    <path d="M7 11.5V16c0 1.2 2.2 2.5 5 2.5s5-1.3 5-2.5v-4.5M21 9v5.5" />
  </Svg>
);
export const IconClock = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7.5V12l3 2" />
  </Svg>
);
export const IconMoon = (p: IconProps) => (
  <Svg {...p}>
    <path d="M20 14.2A8 8 0 0 1 9.8 4a8 8 0 1 0 10.2 10.2z" />
  </Svg>
);
export const IconSun = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="3.6" />
    <path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4" />
  </Svg>
);
export const IconCheck = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="m8.3 12.3 2.6 2.6 4.8-5.4" />
  </Svg>
);
export const IconChart = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 20V4M4 20h16" />
    <path d="M8 16v-4M12.5 16V8M17 16v-6.5" />
  </Svg>
);
export const IconEdit = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z" />
    <path d="m14.5 7.5 3 3" />
  </Svg>
);
export const IconDoc = (p: IconProps) => (
  <Svg {...p}>
    <path d="M7 3.5h7l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-9.5A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5z" />
    <path d="M14 3.5V8h4M9 12.5h6M9 16h4" />
  </Svg>
);
export const IconDevice = (p: IconProps) => (
  <Svg {...p}>
    <rect x="6" y="3" width="12" height="18" rx="2.5" />
    <path d="M10.5 18h3" />
  </Svg>
);
export const IconShield = (p: IconProps) => (
  <Svg {...p}>
    <path d="M12 3.5 5 6v5.5c0 4.3 2.9 7.4 7 9 4.1-1.6 7-4.7 7-9V6z" />
    <path d="m9 12 2.2 2.2L15.2 10" />
  </Svg>
);
export const IconUserPlus = (p: IconProps) => (
  <Svg {...p}>
    <circle cx="10" cy="8" r="3.2" />
    <path d="M4 19.5c.4-3.2 3-5 6-5 1.4 0 2.7.4 3.7 1.1M18 9v6M15 12h6" />
  </Svg>
);
export const IconLogout = (p: IconProps) => (
  <Svg {...p}>
    <path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14M10 12h10M17 8.5 20.5 12 17 15.5" />
  </Svg>
);
export const IconMenu = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 7h16M4 12h16M4 17h10" />
  </Svg>
);
export const IconClose = (p: IconProps) => (
  <Svg {...p}>
    <path d="M6 6l12 12M18 6 6 18" />
  </Svg>
);
export const IconBuilding = (p: IconProps) => (
  <Svg {...p}>
    <path d="M4 20V8.5L12 4l8 4.5V20M2.5 20h19M9 20v-5h6v5M8.5 11h1M14.5 11h1" />
  </Svg>
);
export const IconBell = (p: IconProps) => (
  <Svg {...p}>
    <path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15zM10 20.5h4" />
  </Svg>
);
export const IconArrowRight = (p: IconProps) => (
  <Svg {...p}>
    <path d="M5 12h14M13 6l6 6-6 6" />
  </Svg>
);
