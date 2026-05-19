import { describe, expect, it } from "vitest";
import { currentSiteEnvironment, siteUrlFor } from "./siteLinks";

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

    expect(siteUrlFor(rustGame, "preview")).toBe("http://127.0.0.1/ai_defense");
    expect(siteUrlFor(rustGame, "production")).toBe(
      "https://webhatchery.au/games/ai_defense",
    );
  });

  it("selects production only on WebHatchery hosts", () => {
    expect(currentSiteEnvironment("webhatchery.au")).toBe("production");
    expect(currentSiteEnvironment("www.webhatchery.au")).toBe("production");
    expect(currentSiteEnvironment("127.0.0.1")).toBe("preview");
  });
});
