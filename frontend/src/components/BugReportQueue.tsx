import React, { useState } from "react";
import type { BugReport } from "../types";
import type { BugReportModeration } from "../api/bugReports";

interface BugReportQueueProps {
  reports: BugReport[];
  onModerate: (id: number, payload: BugReportModeration) => Promise<void>;
}

// All report content is rendered as plain text (React escapes it) — never as HTML —
// because these come from an unauthenticated, internet-facing endpoint.
export const BugReportQueue: React.FC<BugReportQueueProps> = ({
  reports,
  onModerate,
}) => {
  const [busyId, setBusyId] = useState<number | null>(null);
  const [priorities, setPriorities] = useState<Record<number, string>>({});

  const act = async (id: number, payload: BugReportModeration) => {
    setBusyId(id);
    try {
      await onModerate(id, payload);
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section className="panel queue-panel" aria-label="Player bug reports">
      <div className="panel-header">
        <h2>Player Bug Reports</h2>
        <span>{reports.length} pending</span>
      </div>
      <div className="task-stack">
        {reports.map((report) => {
          const priority = priorities[report.id] ?? "medium";
          const busy = busyId === report.id;
          const unlinked = report.project_id === null;

          return (
            <article key={report.id} className="task-item">
              <div>
                <strong>{report.summary}</strong>
                <span>{report.project_slug}</span>
              </div>
              <p>{report.description}</p>
              <div className="bug-report-facts">
                {report.contact ? <span>contact: {report.contact}</span> : null}
                {report.game_version ? (
                  <span>v{report.game_version}</span>
                ) : null}
                {report.created_at ? <span>{report.created_at}</span> : null}
              </div>
              {unlinked ? (
                <p className="bug-report-warn">
                  No matching project — link the slug in Projects before approving.
                </p>
              ) : null}
              <div className="task-meta bug-report-actions">
                <select
                  value={priority}
                  disabled={busy}
                  aria-label="Priority for approved task"
                  onChange={(event) =>
                    setPriorities((current) => ({
                      ...current,
                      [report.id]: event.target.value,
                    }))
                  }
                >
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                </select>
                <button
                  type="button"
                  disabled={busy || unlinked}
                  onClick={() =>
                    void act(report.id, {
                      action: "approve",
                      priority: priority as "low" | "medium" | "high",
                    })
                  }
                >
                  Approve → Fix Queue
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void act(report.id, { action: "reject" })}
                >
                  Reject
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void act(report.id, { action: "spam" })}
                >
                  Spam
                </button>
              </div>
            </article>
          );
        })}
        {reports.length === 0 ? (
          <div className="empty-state">No pending reports.</div>
        ) : null}
      </div>
    </section>
  );
};
