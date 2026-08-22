import { describe, expect, it } from "vitest";
import {
  currentSiteEnvironment,
  currentWebhatcheryHomeUrl,
  siteUrlFor,
  webhatcheryHomeUrl,
} from "./siteLinks";

const project = {
  slug: "project_roost",
  category: "app",
  preview_url: null,
  production_url: null,
};

describe("siteLinks", () => {
  it("builds preview and production URLs from the project slug", () => {
    expect(siteUrlFor(project, "preview")).toBe(
      "http://127.0.0.1/project_roost",
    );
    expect(siteUrlFor(project, "production")).toBe(
      "https://webhatchery.au/project_roost",
    );
  });

  it("uses stored URLs without trailing slash churn", () => {
    expect(
      siteUrlFor(
        {
          ...project,
          preview_url: "http://127.0.0.1/project_roost/",
        },
        "preview",
      ),
    ).toBe("http://127.0.0.1/project_roost");
  });

  it("uses the games production folder for RustGames", () => {
    const rustGame = {
      slug: "rust_ai_defense",
      category: "rust-game",
      preview_url: "http://127.0.0.1/ai_defense/",
      production_url: "https://webhatchery.au/ai_defense/",
    };

    expect(siteUrlFor(rustGame, "preview")).toBe(
      "http://127.0.0.1/games/ai_defense",
    );
    expect(siteUrlFor(rustGame, "production")).toBe(
      "https://webhatchery.au/games/ai_defense",
    );
  });

  it("recognizes the Rust slug even when its category is stale", () => {
    expect(
      siteUrlFor(
        {
          slug: "rust_idle_hands",
          category: "app",
          preview_url: null,
          production_url: null,
        },
        "production",
      ),
    ).toBe("https://webhatchery.au/games/idle_hands");
  });

  it("selects production only on WebHatchery hosts", () => {
    expect(currentSiteEnvironment("webhatchery.au")).toBe("production");
    expect(currentSiteEnvironment("www.webhatchery.au")).toBe("production");
    expect(currentSiteEnvironment("127.0.0.1")).toBe("preview");
  });

  it("builds the main-site home URL for each environment", () => {
    expect(webhatcheryHomeUrl("preview")).toBe("http://127.0.0.1/");
    expect(webhatcheryHomeUrl("production")).toBe("https://webhatchery.au/");
    expect(currentWebhatcheryHomeUrl("127.0.0.1")).toBe(
      "http://127.0.0.1/",
    );
    expect(currentWebhatcheryHomeUrl("webhatchery.au")).toBe(
      "https://webhatchery.au/",
    );
  });
});
