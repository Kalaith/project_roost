import React from "react";
import type { DashboardSummary } from "../types";
import { formatScore } from "../utils/score";
import { AlertIcon, ChartIcon, FolderIcon, TasksIcon } from "./icons";

interface DashboardCardsProps {
  summary: DashboardSummary | null;
}

export const DashboardCards: React.FC<DashboardCardsProps> = ({ summary }) => {
  const todoCount = summary?.tasks.todo ?? 0;
  const blockedCount = summary?.tasks.blocked ?? 0;
  const doingCount = summary?.tasks.doing ?? 0;
  const highRisk = summary?.high_risk_projects ?? 0;
  const categoryCount = summary ? Object.keys(summary.categories).length : 0;

  const taskContext =
    blockedCount > 0
      ? `${blockedCount} blocked`
      : doingCount > 0
        ? `${doingCount} in progress`
        : "Nothing blocked";

  return (
    <section className="metric-grid" aria-label="Portfolio summary">
      <article className="metric-card">
        <div className="metric-icon">
          <FolderIcon />
        </div>
        <div className="metric-body">
          <span className="metric-label">Projects</span>
          <strong>{summary?.total_projects ?? "-"}</strong>
          <span className="metric-context">
            {summary ? `${categoryCount} categories` : "Loading"}
          </span>
        </div>
      </article>
      <article className="metric-card">
        <div className="metric-icon good">
          <ChartIcon />
        </div>
        <div className="metric-body">
          <span className="metric-label">Average Score</span>
          <strong>{formatScore(summary?.average_overall)}</strong>
          <span className="metric-context">Latest reviews</span>
        </div>
      </article>
      <article className="metric-card">
        <div className={`metric-icon ${highRisk > 0 ? "bad" : "good"}`}>
          <AlertIcon />
        </div>
        <div className="metric-body">
          <span className="metric-label">High Risk</span>
          <strong>{summary?.high_risk_projects ?? "-"}</strong>
          <span
            className={`metric-context ${highRisk > 0 ? "bad" : "good"}`}
          >
            {highRisk > 0 ? "Needs attention" : "All clear"}
          </span>
        </div>
      </article>
      <article className="metric-card">
        <div className="metric-icon warn">
          <TasksIcon />
        </div>
        <div className="metric-body">
          <span className="metric-label">Open Tasks</span>
          <strong>{todoCount + blockedCount}</strong>
          <span className="metric-context">{taskContext}</span>
        </div>
      </article>
    </section>
  );
};
