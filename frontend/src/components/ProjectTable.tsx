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
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Project</th>
              <th>Category</th>
              <th>Risk</th>
              <th>Overall</th>
              <th>Security</th>
              <th>Status</th>
              <th>Site</th>
            </tr>
          </thead>
          <tbody>
            {projects.map((project) => {
              const siteUrl = siteUrlFor(project, siteEnvironment);

              return (
                <tr
                  key={project.id}
                  className={selectedId === project.id ? "selected" : ""}
                  onClick={() => onSelect(project)}
                >
                  <td>
                    <button
                      type="button"
                      className="row-button"
                      onClick={() => onSelect(project)}
                    >
                      <strong>{project.name}</strong>
                      <span>{project.slug}</span>
                    </button>
                  </td>
                  <td>{project.category}</td>
                  <td>
                    <span className={riskClass(project.risk.severity)}>
                      {project.risk.severity}
                    </span>
                  </td>
                  <td>
                    <span
                      className={scoreClass(
                        project.latest_review?.overall_score,
                      )}
                    >
                      {formatScore(project.latest_review?.overall_score)}
                    </span>
                  </td>
                  <td>
                    <span
                      className={scoreClass(
                        project.latest_review?.security_score,
                      )}
                    >
                      {formatScore(project.latest_review?.security_score)}
                    </span>
                  </td>
                  <td>{project.status}</td>
                  <td>
                    <a
                      className="site-shortcut"
                      href={siteUrl}
                      target="_blank"
                      rel="noreferrer"
                      aria-label={`Open ${project.name} site`}
                      onClick={(event) => event.stopPropagation()}
                    >
                      Site
                    </a>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
};
