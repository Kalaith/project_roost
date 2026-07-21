import React, { useEffect, useState } from "react";
import type { DeploymentRecord, Project, ProjectUpdatePayload } from "../types";
import {
  deploymentLabels,
  deploymentPath,
  formatDeploymentDate,
  visibleDeploymentEnvironments,
} from "../utils/deployments";
import {
  PROJECT_CATEGORY_OPTIONS,
  PROJECT_SHAPE_OPTIONS,
  PROJECT_STAGE_OPTIONS,
  PROJECT_STATUS_OPTIONS,
  RISK_OPTIONS,
  groupNameForCategory,
  optionsWithCurrent,
} from "../constants/projectOptions";
import { formatScore, riskClass, scoreClass } from "../utils/score";
import { currentSiteEnvironment, siteUrlFor } from "../utils/siteLinks";

interface ProjectDetailProps {
  project: Project | null;
  deployments: DeploymentRecord[];
  deploymentLatest: Record<string, DeploymentRecord | null>;
  deploymentsLoading: boolean;
  saving: boolean;
  deleting: boolean;
  onSave: (id: number, payload: ProjectUpdatePayload) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
}

interface DetailForm {
  name: string;
  display_name: string;
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
  severity: string;
  show_on_homepage: boolean;
  hidden: boolean;
  archived: boolean;
}

const emptyForm: DetailForm = {
  name: "",
  display_name: "",
  status: "",
  stage: "",
  category: "",
  shape: "",
  summary: "",
  repo_path: "",
  preview_url: "",
  production_url: "",
  version: "",
  repository_url: "",
  severity: "low",
  show_on_homepage: true,
  hidden: false,
  archived: false,
};

const formFromProject = (project: Project): DetailForm => ({
  name: project.name,
  display_name: project.display_name,
  status: project.status,
  stage: project.stage,
  category: project.category,
  shape: project.shape,
  summary: project.summary,
  repo_path: project.repo_path ?? "",
  preview_url: project.preview_url ?? "",
  production_url: project.production_url ?? "",
  version: project.version,
  repository_url: project.repository_url ?? "",
  severity: project.risk.severity,
  show_on_homepage: project.show_on_homepage,
  hidden: project.hidden,
  archived: project.archived,
});

export const ProjectDetail: React.FC<ProjectDetailProps> = ({
  project,
  deployments,
  deploymentLatest,
  deploymentsLoading,
  saving,
  deleting,
  onSave,
  onDelete,
}) => {
  const [form, setForm] = useState<DetailForm>(emptyForm);
  const [editing, setEditing] = useState(false);

  useEffect(() => {
    setEditing(false);
    setForm(project ? formFromProject(project) : emptyForm);
  }, [project]);

  if (!project) {
    return (
      <section className="panel detail-panel">
        <div className="empty-state">No project selected.</div>
      </section>
    );
  }

  const updateField = <K extends keyof DetailForm>(
    field: K,
    value: DetailForm[K],
  ) => {
    setForm((current) => ({ ...current, [field]: value }));
  };

  const cancelEditing = () => {
    setForm(formFromProject(project));
    setEditing(false);
  };

  const save = async () => {
    await onSave(project.id, {
      name: form.name,
      display_name: form.display_name.trim() || null,
      status: form.status,
      stage: form.stage,
      category: form.category,
      shape: form.shape,
      summary: form.summary,
      repo_path: form.repo_path.trim() || null,
      preview_url: form.preview_url.trim() || null,
      production_url: form.production_url.trim() || null,
      version: form.version.trim() || "0.1.0",
      group_name: groupNameForCategory(form.category),
      repository_type: form.repository_url.trim()
        ? "git"
        : project.repository_type,
      repository_url: form.repository_url.trim() || null,
      show_on_homepage: form.show_on_homepage,
      hidden: form.hidden,
      archived: form.archived,
      risk: {
        severity: form.severity,
      },
    });
    setEditing(false);
  };

  const siteEnvironment = currentSiteEnvironment();
  const activeSiteUrl = siteUrlFor(project, siteEnvironment);
  const previewSiteUrl = siteUrlFor(project, "preview");
  const productionSiteUrl = siteUrlFor(project, "production");
  const displayName = project.display_name || project.name;
  const repositoryUrl = project.repository_url?.trim() ?? "";
  const repositoryLabel = repositoryUrl.toLowerCase().includes("github.com")
    ? "GitHub"
    : "Repository";
  const visibleDeployments = deployments.filter((deployment) =>
    visibleDeploymentEnvironments.includes(
      deployment.environment as (typeof visibleDeploymentEnvironments)[number],
    ),
  );
  const stageOptions = optionsWithCurrent(PROJECT_STAGE_OPTIONS, form.stage);
  const shapeOptions = optionsWithCurrent(PROJECT_SHAPE_OPTIONS, form.shape);

  return (
    <section className="panel detail-panel" aria-label="Project detail">
      <div className="panel-header">
        <h2>{displayName}</h2>
        <div className="panel-header-actions">
          <span className={riskClass(project.risk.severity)}>
            {project.risk.severity}
          </span>
          {!editing ? (
            <button
              type="button"
              className="button secondary small"
              onClick={() => setEditing(true)}
            >
              Edit Project
            </button>
          ) : null}
        </div>
      </div>

      <div className="score-strip">
        <span className={scoreClass(project.latest_review?.frontend_score)}>
          FE {formatScore(project.latest_review?.frontend_score)}
        </span>
        <span className={scoreClass(project.latest_review?.backend_score)}>
          BE {formatScore(project.latest_review?.backend_score)}
        </span>
        <span className={scoreClass(project.latest_review?.security_score)}>
          Sec {formatScore(project.latest_review?.security_score)}
        </span>
        <span className={scoreClass(project.latest_review?.overall_score)}>
          All {formatScore(project.latest_review?.overall_score)}
        </span>
      </div>

      {!editing ? (
        <>
          <div className="fact-grid">
            <div className="fact">
              <span>Status</span>
              <strong>{project.status}</strong>
            </div>
            <div className="fact">
              <span>Stage</span>
              <strong>{project.stage}</strong>
            </div>
            <div className="fact">
              <span>Category</span>
              <strong>{project.category}</strong>
            </div>
            <div className="fact">
              <span>Shape</span>
              <strong>{project.shape}</strong>
            </div>
            <div className="fact">
              <span>Version</span>
              <strong>{project.version}</strong>
            </div>
            <div className="fact">
              <span>Visibility</span>
              <strong>
                {project.hidden
                  ? "Hidden"
                  : project.archived
                    ? "Archived"
                    : project.show_on_homepage
                      ? "On frontpage"
                      : "Listed"}
              </strong>
            </div>
          </div>
          {project.summary ? (
            <p className="detail-summary-text">{project.summary}</p>
          ) : null}
        </>
      ) : null}

      {editing ? (
      <>
      <div className="detail-grid">
        <label className="field">
          <span>Name</span>
          <input
            value={form.name}
            onChange={(event) => updateField("name", event.target.value)}
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
            {stageOptions.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Category</span>
          <select
            value={form.category}
            onChange={(event) => updateField("category", event.target.value)}
          >
            {PROJECT_CATEGORY_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
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
            {shapeOptions.map((option) => (
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
        <label className="field">
          <span>Risk</span>
          <select
            value={form.severity}
            onChange={(event) => updateField("severity", event.target.value)}
          >
            {RISK_OPTIONS.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="detail-grid full-field">
        <label className="field">
          <span>Repository Path</span>
          <input
            value={form.repo_path}
            onChange={(event) => updateField("repo_path", event.target.value)}
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
            onChange={(event) => updateField("preview_url", event.target.value)}
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

      <div className="toggle-row full-field">
        <label className="checkbox-field">
          <input
            type="checkbox"
            checked={form.show_on_homepage}
            onChange={(event) =>
              updateField("show_on_homepage", event.target.checked)
            }
          />
          <span>Show on Frontpage</span>
        </label>
        <label className="checkbox-field">
          <input
            type="checkbox"
            checked={form.archived}
            onChange={(event) => updateField("archived", event.target.checked)}
          />
          <span>Archived</span>
        </label>
        <label className="checkbox-field">
          <input
            type="checkbox"
            checked={form.hidden}
            onChange={(event) => updateField("hidden", event.target.checked)}
          />
          <span>Hidden</span>
        </label>
      </div>

      <label className="field full-field">
        <span>Summary</span>
        <textarea
          value={form.summary}
          onChange={(event) => updateField("summary", event.target.value)}
          rows={7}
        />
      </label>
      </>
      ) : null}

      {project.latest_review?.priority_fix ? (
        <div className="priority-box">
          <strong>Priority fix</strong>
          <p>{project.latest_review.priority_fix}</p>
        </div>
      ) : null}

      <div className="site-card" aria-label="Site links">
        <div className="site-card-header">
          <div className="site-card-title">
            <span>Site</span>
            <strong>{project.slug}</strong>
          </div>
          <a
            className="button secondary site-open-link"
            href={activeSiteUrl}
            target="_blank"
            rel="noreferrer"
          >
            Open Site
          </a>
        </div>
        <div className="site-link-grid">
          <a href={previewSiteUrl} target="_blank" rel="noreferrer">
            <span>Preview</span>
            <strong>{previewSiteUrl}</strong>
          </a>
          <a href={productionSiteUrl} target="_blank" rel="noreferrer">
            <span>Production</span>
            <strong>{productionSiteUrl}</strong>
          </a>
          {repositoryUrl !== "" ? (
            <a
              href={repositoryUrl}
              target="_blank"
              rel="noreferrer"
              aria-label={`Open ${displayName} ${repositoryLabel}`}
            >
              <span>{repositoryLabel}</span>
              <strong>{repositoryUrl}</strong>
            </a>
          ) : null}
        </div>
        {project.repo_path ? (
          <span className="repo-path">{project.repo_path}</span>
        ) : null}
      </div>

      <div
        className="publish-section"
        aria-label="Selected project publish log"
      >
        <div className="subsection-header">
          <h3>Publish Log</h3>
          <span>
            {deploymentsLoading
              ? "Loading"
              : `${visibleDeployments.length} recent`}
          </span>
        </div>

        <div className="publish-summary-grid">
          {visibleDeploymentEnvironments.map((environment) => {
            const label = deploymentLabels[environment];
            const deployment = deploymentLatest[environment] ?? null;

            return (
              <article key={environment} className="publish-summary-card">
                <strong>{label}</strong>
                <span>{formatDeploymentDate(deployment?.deployed_at)}</span>
                <p>
                  {deployment
                    ? `${deployment.publish_mode} (${deployment.status})`
                    : "No publish recorded."}
                </p>
              </article>
            );
          })}
        </div>

        <div className="publish-log-stack">
          {deploymentsLoading ? (
            <p className="muted-line">Loading publish log.</p>
          ) : null}
          {!deploymentsLoading && visibleDeployments.length === 0 ? (
            <p className="muted-line">
              No publish logs recorded for this site.
            </p>
          ) : null}
          {!deploymentsLoading
            ? visibleDeployments.map((deployment) => (
                <article key={deployment.id} className="publish-log-item">
                  <div>
                    <strong>
                      {deploymentLabels[deployment.environment] ??
                        deployment.environment}
                    </strong>
                    <span>{formatDeploymentDate(deployment.deployed_at)}</span>
                  </div>
                  <p>
                    {deployment.publish_mode} via {deployment.target_type} (
                    {deployment.status})
                  </p>
                  <span className="publish-path">
                    {deploymentPath(deployment)}
                  </span>
                </article>
              ))
            : null}
        </div>
      </div>

      {editing ? (
        <div className="detail-footer">
          <button
            type="button"
            className="button primary wide full-width"
            onClick={save}
            disabled={saving || deleting}
          >
            {saving ? "Saving…" : "Save Changes"}
          </button>
          <button
            type="button"
            className="button secondary wide"
            onClick={cancelEditing}
            disabled={saving || deleting}
          >
            Cancel
          </button>
          <button
            type="button"
            className="button danger wide"
            onClick={() => {
              if (
                window.confirm(
                  `Delete "${displayName}" from Project Roost? Its reviews and tasks will be removed and the project will be hidden from the frontpage.`,
                )
              ) {
                void onDelete(project.id);
              }
            }}
            disabled={saving || deleting}
          >
            {deleting ? "Deleting…" : "Delete Project"}
          </button>
        </div>
      ) : null}
    </section>
  );
};
