import React, { useState } from "react";
import {
  PROJECT_CATEGORY_OPTIONS,
  PROJECT_SHAPE_OPTIONS,
  PROJECT_STAGE_OPTIONS,
  PROJECT_STATUS_OPTIONS,
  RISK_OPTIONS,
  groupNameForCategory,
} from "../constants/projectOptions";
import type { ProjectCreatePayload, ProjectDiscoveryCandidate } from "../types";

interface ProjectCreatePanelProps {
  creating: boolean;
  discoveries: ProjectDiscoveryCandidate[];
  discovering: boolean;
  discoveryMessage: string;
  onCreate: (payload: ProjectCreatePayload) => Promise<void>;
  onDiscover: (params: { search?: string; source?: string }) => Promise<void>;
}

interface CreateForm {
  name: string;
  display_name: string;
  slug: string;
  status: string;
  stage: string;
  category: string;
  shape: string;
  summary: string;
  repo_path: string;
  preview_url: string;
  production_url: string;
  version: string;
  repository_url: string;
  risk_severity: string;
  show_on_homepage: boolean;
}

const emptyForm: CreateForm = {
  name: "",
  display_name: "",
  slug: "",
  status: "Concept",
  stage: "React",
  category: "app",
  shape: "frontend+backend",
  summary: "",
  repo_path: "",
  preview_url: "",
  production_url: "",
  version: "0.1.0",
  repository_url: "",
  risk_severity: "low",
  show_on_homepage: true,
};

export const ProjectCreatePanel: React.FC<ProjectCreatePanelProps> = ({
  creating,
  discoveries,
  discovering,
  discoveryMessage,
  onCreate,
  onDiscover,
}) => {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<CreateForm>(emptyForm);
  const [discoverySearch, setDiscoverySearch] = useState("");
  const [discoverySource, setDiscoverySource] = useState("all");

  const updateField = <K extends keyof CreateForm>(
    field: K,
    value: CreateForm[K],
  ) => {
    setForm((current) => ({ ...current, [field]: value }));
  };

  const changeCategory = (category: string) => {
    setForm((current) => ({
      ...current,
      category,
      slug: "",
      stage:
        category === "game"
          ? "Game"
          : category === "rust-game"
            ? "Rust"
            : category === "template"
              ? "Template"
              : current.stage,
      shape:
        category === "rust-game"
          ? "rust+webgl"
          : category === "template"
            ? "template"
            : category === "game"
              ? "frontend-only"
              : current.shape,
    }));
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();

    const payload: ProjectCreatePayload = {
      name: form.name.trim(),
      display_name: form.display_name.trim() || form.name.trim(),
      slug: form.slug.trim() || undefined,
      status: form.status,
      stage: form.stage,
      category: form.category,
      shape: form.shape,
      summary: form.summary.trim(),
      repo_path: form.repo_path.trim() || null,
      preview_url: form.preview_url.trim() || null,
      production_url: form.production_url.trim() || null,
      version: form.version.trim() || "0.1.0",
      group_name: groupNameForCategory(form.category),
      repository_type: form.repository_url.trim() ? "git" : "local",
      repository_url: form.repository_url.trim() || null,
      show_on_homepage: form.show_on_homepage,
      hidden: false,
      risk: {
        severity: form.risk_severity,
      },
    };

    await onCreate(payload);
    setForm(emptyForm);
    setOpen(false);
  };

  const applyDiscovery = (candidate: ProjectDiscoveryCandidate) => {
    setForm({
      name: candidate.name,
      display_name: candidate.display_name,
      slug: candidate.slug ?? "",
      status: candidate.status,
      stage: candidate.stage,
      category: candidate.category,
      shape: candidate.shape,
      summary: candidate.summary ?? "",
      repo_path: candidate.repo_path ?? "",
      preview_url: candidate.preview_url ?? "",
      production_url: candidate.production_url ?? "",
      version: candidate.version ?? "0.1.0",
      repository_url: candidate.repository_url ?? "",
      risk_severity: candidate.risk?.severity ?? "low",
      show_on_homepage: candidate.show_on_homepage ?? true,
    });
    setOpen(true);
  };

  return (
    <section className="panel create-panel" aria-label="Create project">
      <div className="panel-header">
        <h2>Add Project</h2>
        <button
          type="button"
          className="button secondary"
          onClick={() => setOpen((current) => !current)}
        >
          {open ? "Close" : "New Project"}
        </button>
      </div>

      <div className="discovery-bar" aria-label="Discover project folders">
        <label className="field search-field">
          <span>Smart Search</span>
          <input
            value={discoverySearch}
            onChange={(event) => setDiscoverySearch(event.target.value)}
            placeholder="Folder, title, source"
          />
        </label>
        <label className="field">
          <span>Source</span>
          <select
            value={discoverySource}
            onChange={(event) => setDiscoverySource(event.target.value)}
          >
            <option value="all">All</option>
            <option value="apps">Apps</option>
            <option value="games">Games</option>
            <option value="rust-games">Rust Games</option>
          </select>
        </label>
        <button
          type="button"
          className="button secondary"
          onClick={() =>
            void onDiscover({
              search: discoverySearch,
              source: discoverySource,
            })
          }
          disabled={discovering}
        >
          {discovering ? "Searching" : "Find New"}
        </button>
      </div>

      {discoveryMessage ? (
        <p className="status-line">{discoveryMessage}</p>
      ) : null}

      {discoveries.length > 0 ? (
        <div className="discovery-list">
          {discoveries.map((candidate) => (
            <article
              key={`${candidate.source}-${candidate.slug}`}
              className="discovery-item"
            >
              <div>
                <strong>{candidate.display_name || candidate.name}</strong>
                <span>
                  {candidate.name} / {candidate.source} / {candidate.category} /{" "}
                  {candidate.production_url}
                </span>
              </div>
              <span className="confidence-pill">{candidate.confidence}%</span>
              <button
                type="button"
                className="button secondary"
                onClick={() => applyDiscovery(candidate)}
              >
                Use
              </button>
            </article>
          ))}
        </div>
      ) : null}

      {open ? (
        <form className="create-project-form" onSubmit={submit}>
          <div className="detail-grid">
            <label className="field">
              <span>Name</span>
              <input
                value={form.name}
                onChange={(event) => updateField("name", event.target.value)}
                required
              />
            </label>
            <label className="field">
              <span>Display Name</span>
              <input
                value={form.display_name}
                onChange={(event) =>
                  updateField("display_name", event.target.value)
                }
              />
            </label>
            <label className="field">
              <span>Slug</span>
              <input
                value={form.slug}
                onChange={(event) => updateField("slug", event.target.value)}
              />
            </label>
            <label className="field">
              <span>Category</span>
              <select
                value={form.category}
                onChange={(event) => changeCategory(event.target.value)}
              >
                {PROJECT_CATEGORY_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Status</span>
              <select
                value={form.status}
                onChange={(event) => updateField("status", event.target.value)}
              >
                {PROJECT_STATUS_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Stage</span>
              <select
                value={form.stage}
                onChange={(event) => updateField("stage", event.target.value)}
              >
                {PROJECT_STAGE_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Shape</span>
              <select
                value={form.shape}
                onChange={(event) => updateField("shape", event.target.value)}
              >
                {PROJECT_SHAPE_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Risk</span>
              <select
                value={form.risk_severity}
                onChange={(event) =>
                  updateField("risk_severity", event.target.value)
                }
              >
                {RISK_OPTIONS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Version</span>
              <input
                value={form.version}
                onChange={(event) => updateField("version", event.target.value)}
              />
            </label>
            <label className="field checkbox-field">
              <input
                type="checkbox"
                checked={form.show_on_homepage}
                onChange={(event) =>
                  updateField("show_on_homepage", event.target.checked)
                }
              />
              <span>Show on Frontpage</span>
            </label>
          </div>

          <label className="field full-field">
            <span>Summary</span>
            <textarea
              value={form.summary}
              onChange={(event) => updateField("summary", event.target.value)}
              rows={4}
            />
          </label>

          <div className="detail-grid full-field">
            <label className="field">
              <span>Repository Path</span>
              <input
                value={form.repo_path}
                onChange={(event) =>
                  updateField("repo_path", event.target.value)
                }
              />
            </label>
            <label className="field">
              <span>Repository URL</span>
              <input
                value={form.repository_url}
                onChange={(event) =>
                  updateField("repository_url", event.target.value)
                }
              />
            </label>
            <label className="field">
              <span>Preview URL</span>
              <input
                value={form.preview_url}
                onChange={(event) =>
                  updateField("preview_url", event.target.value)
                }
              />
            </label>
            <label className="field">
              <span>Production URL</span>
              <input
                value={form.production_url}
                onChange={(event) =>
                  updateField("production_url", event.target.value)
                }
              />
            </label>
          </div>

          <button
            type="submit"
            className="button primary wide"
            disabled={creating}
          >
            {creating ? "Creating" : "Create Project"}
          </button>
        </form>
      ) : null}
    </section>
  );
};
