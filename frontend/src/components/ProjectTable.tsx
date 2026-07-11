import React from "react";
import type { Project } from "../types";
import { formatScore, riskClass, scoreClass } from "../utils/score";
import { currentSiteEnvironment, siteUrlFor } from "../utils/siteLinks";

interface ProjectTableProps {
  projects: Project[];
  selectedId: number | null;
  onSelect: (project: Project) => void;
}

export const ProjectTable: React.FC<ProjectTableProps> = ({
  projects,
  selectedId,
  onSelect,
}) => {
  const siteEnvironment = currentSiteEnvironment();

  return (
    <section className="panel project-list" aria-label="Project library">
      <div className="panel-header">
        <h2>Project Library</h2>
        <span>{projects.length} shown</span>
      </div>
      <div className="project-card-stack">
        {projects.map((project) => {
          const siteUrl = siteUrlFor(project, siteEnvironment);
          const displayName = project.display_name || project.name;
          const projectIdentifier =
            project.name === project.slug
              ? project.name
              : `${project.name} / ${project.slug}`;

          return (
            <article
              key={project.id}
              className={`project-card ${
                selectedId === project.id ? "selected" : ""
              }`}
              onClick={() => onSelect(project)}
            >
              <div className="project-card-main">
                <div className="project-card-title">
                  <button
                    type="button"
                    className="row-button"
                    onClick={() => onSelect(project)}
                  >
                    <strong>{displayName}</strong>
                  </button>
                </div>
                <span className="project-card-sub">
                  {projectIdentifier} • {project.category}
                </span>
              </div>
              <div className="project-card-stats">
                <div className="stat-block">
                  <span>Risk</span>
                  <span className={riskClass(project.risk.severity)}>
                    {project.risk.severity}
                  </span>
                </div>
                <div className="stat-block">
                  <span>Overall</span>
                  <span
                    className={scoreClass(project.latest_review?.overall_score)}
                  >
                    {formatScore(project.latest_review?.overall_score)}
                  </span>
                </div>
                <div className="stat-block">
                  <span>Security</span>
                  <span
                    className={scoreClass(
                      project.latest_review?.security_score,
                    )}
                  >
                    {formatScore(project.latest_review?.security_score)}
                  </span>
                </div>
                <div className="stat-block">
                  <span>Status</span>
                  <span className="badge neutral">{project.status}</span>
                </div>
                <a
                  className="site-shortcut"
                  href={siteUrl}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={`Open ${displayName} site`}
                  onClick={(event) => event.stopPropagation()}
                >
                  Site
                </a>
              </div>
            </article>
          );
        })}
        {projects.length === 0 ? (
          <div className="empty-state">No projects match the filters.</div>
        ) : null}
      </div>
    </section>
  );
};
