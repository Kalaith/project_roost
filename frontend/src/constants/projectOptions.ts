export const PROJECT_STATUS_OPTIONS = ["Concept", "MVP", "Complete"] as const;

export const PROJECT_CATEGORY_OPTIONS = [
  { value: "app", label: "App", groupName: "apps" },
  { value: "game", label: "Game", groupName: "games" },
  { value: "rust-game", label: "Rust Game", groupName: "rust_games" },
  { value: "template", label: "Template", groupName: "templates" },
] as const;

export const PROJECT_STAGE_OPTIONS = [
  "Static",
  "React",
  "API",
  "Auth",
  "Fullstack",
  "Dashboard",
  "Tool",
  "Generator",
  "Companion",
  "Game",
  "Rust",
  "Template",
  "Design",
] as const;

export const PROJECT_SHAPE_OPTIONS = [
  "frontend-only",
  "frontend+backend",
  "rust+webgl",
  "rust+webgl+server",
  "template",
  "design",
  "unknown",
] as const;

export const RISK_OPTIONS = ["low", "medium", "high", "critical"] as const;

export const groupNameForCategory = (category: string): string =>
  PROJECT_CATEGORY_OPTIONS.find((option) => option.value === category)
    ?.groupName ?? "other";

export const optionsWithCurrent = <T extends readonly string[]>(
  options: T,
  current: string,
): string[] => {
  const trimmed = current.trim();
  return trimmed !== "" && !(options as readonly string[]).includes(trimmed)
    ? [...options, trimmed]
    : [...options];
};
