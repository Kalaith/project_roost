/**
 * @vitest-environment jsdom
 */
import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { ProjectDetail } from "./ProjectDetail";
import { ProjectTable } from "./ProjectTable";
import type { Project } from "../types";

const project: Project = {
  id: 1,
  slug: "project_roost",
  name: "Project Roost",
  display_name: "Project Roost",
  category: "app",
  status: "watch",
  stage: "prototype",
  shape: "frontend+backend",
  summary: "Portfolio registry.",
  repo_path: "H:\\WebHatchery\\apps\\project_roost",
  preview_url: null,
  production_url: null,
  version: "0.1.0",
  group_name: "apps",
  repository_type: "local",
  repository_url: null,
  hidden: false,
  show_on_homepage: true,
  created_at: null,
  updated_at: null,
  latest_review: null,
  risk: {
    severity: "low",
    auth_risk: "standard",
    data_risk: "standard",
    env_risk: "standard",
    ownership_risk: "standard",
    notes: "",
  },
};

describe("project site links", () => {
  it("shows a current-environment site shortcut in the table", () => {
    render(
      <ProjectTable
        projects={[project]}
        selectedId={null}
        onSelect={vi.fn()}
      />,
    );

    expect(
      screen.getByRole("link", { name: "Open Project Roost site" }),
    ).toHaveAttribute("href", "http://127.0.0.1/project_roost");
  });

  it("includes the current site and environment links in the detail card", () => {
    render(
      <ProjectDetail
        project={{
          ...project,
          repository_url: "https://github.com/webhatchery/project_roost",
        }}
        deployments={[]}
        deploymentLatest={{}}
        deploymentsLoading={false}
        saving={false}
        deleting={false}
        onSave={vi.fn(async () => undefined)}
        onDelete={vi.fn(async () => undefined)}
      />,
    );

    expect(screen.getByRole("link", { name: "Open Site" })).toHaveAttribute(
      "href",
      "http://127.0.0.1/project_roost",
    );
    expect(screen.getByText("http://127.0.0.1/project_roost")).toBeVisible();
    expect(
      screen.getByText("https://webhatchery.au/project_roost"),
    ).toBeVisible();
    expect(
      screen.getByRole("link", {
        name: "Open Project Roost GitHub",
      }),
    ).toHaveAttribute("href", "https://github.com/webhatchery/project_roost");
  });
});
