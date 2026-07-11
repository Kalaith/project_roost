import React from "react";

interface IconProps {
  className?: string;
}

const Svg: React.FC<React.PropsWithChildren<IconProps>> = ({
  className,
  children,
}) => (
  <svg
    className={className}
    width="20"
    height="20"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    {children}
  </svg>
);

export const FolderIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
  </Svg>
);

export const ChartIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <path d="M5 20v-7" />
    <path d="M12 20V5" />
    <path d="M19 20v-11" />
    <path d="M3 20h18" />
  </Svg>
);

export const AlertIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <path d="M10.3 4.1 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.1a2 2 0 0 0-3.4 0z" />
    <path d="M12 9v4" />
    <path d="M12 17h.01" />
  </Svg>
);

export const TasksIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <path d="M9 6h12" />
    <path d="M9 12h12" />
    <path d="M9 18h12" />
    <path d="M3 6l1 1 2-2" />
    <path d="M3 12l1 1 2-2" />
    <path d="M3 18l1 1 2-2" />
  </Svg>
);

export const SunIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2v2" />
    <path d="M12 20v2" />
    <path d="M4.9 4.9l1.4 1.4" />
    <path d="M17.7 17.7l1.4 1.4" />
    <path d="M2 12h2" />
    <path d="M20 12h2" />
    <path d="M4.9 19.1l1.4-1.4" />
    <path d="M17.7 6.3l1.4-1.4" />
  </Svg>
);

export const MoonIcon: React.FC<IconProps> = (props) => (
  <Svg {...props}>
    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
  </Svg>
);
