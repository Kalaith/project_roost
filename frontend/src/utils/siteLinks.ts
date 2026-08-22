import type { Project } from "../types";

export type SiteEnvironment = "preview" | "production";

const siteBases: Record<SiteEnvironment, string> = {
  preview: "http://127.0.0.1",
  production: "https://webhatchery.au",
};

const trimTrailingSlash = (value: string): string => value.replace(/\/+$/, "");

const slugPath = (slug: string): string => slug.replace(/^\/+|\/+$/g, "");

const rustGamePath = (slug: string): string =>
  slugPath(slug).replace(/^rust_/, "");

const isRustGame = (project: Pick<Project, "slug" | "category">): boolean =>
  project.category === "rust-game" ||
  slugPath(project.slug).startsWith("rust_");

export const siteUrlFor = (
  project: Pick<
    Project,
    "slug" | "category" | "preview_url" | "production_url"
  >,
  environment: SiteEnvironment,
): string => {
  const storedUrl =
    environment === "production" ? project.production_url : project.preview_url;

  if (isRustGame(project)) {
    const gamePath = rustGamePath(project.slug);
    return `${siteBases[environment]}/games/${gamePath}`;
  }

  if (storedUrl && storedUrl.trim() !== "") {
    return trimTrailingSlash(storedUrl.trim());
  }

  return `${siteBases[environment]}/${slugPath(project.slug)}`;
};

export const currentSiteEnvironment = (
  hostname = window.location.hostname,
): SiteEnvironment => {
  const normalizedHost = hostname.toLowerCase();

  return normalizedHost === "webhatchery.au" ||
    normalizedHost.endsWith(".webhatchery.au")
    ? "production"
    : "preview";
};
