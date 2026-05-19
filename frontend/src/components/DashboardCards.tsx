import React from "react";
import type { DashboardSummary } from "../types";
import { formatScore } from "../utils/score";

interface DashboardCardsProps {
  summary: DashboardSummary | null;
}

export const DashboardCards: React.FC<DashboardCardsProps> = ({ summary }) => {
  const todoCount = summary?.tasks.todo ?? 0;
  const blockedCount = summary?.tasks.blocked ?? 0;

  return (
    <section className="metric-grid" aria-label="Portfolio summary">
      <article className="metric-card">
        <span className="metric-label">Projects</span>
        <strong>{summary?.total_projects ?? "-"}</strong>
      </article>
      <article className="metric-card">
        <span className="metric-label">Average</span>
        <strong>{formatScore(summary?.average_overall)}</strong>
      </article>
      <article className="metric-card">
        <span className="metric-label">High risk</span>
        <strong>{summary?.high_risk_projects ?? "-"}</strong>
      </article>
      <article className="metric-card">
        <span className="metric-label">Open tasks</span>
        <strong>{todoCount + blockedCount}</strong>
      </article>
    </section>
  );
};
