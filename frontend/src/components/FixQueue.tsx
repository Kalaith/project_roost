import React from "react";
import type { ProjectTask } from "../types";

interface FixQueueProps {
  tasks: ProjectTask[];
  onTaskStatusChange: (id: number, status: string) => Promise<void>;
}

const priorityTone = (priority: string): string => {
  switch (priority) {
    case "high":
      return "bad";
    case "medium":
      return "warn";
    default:
      return "good";
  }
};

export const FixQueue: React.FC<FixQueueProps> = ({
  tasks,
  onTaskStatusChange,
}) => (
  <section className="panel queue-panel" aria-label="Fix queue">
    <div className="panel-header">
      <h2>Fix Queue</h2>
      <span>{tasks.length} open</span>
    </div>
    <div className="task-stack">
      {tasks.slice(0, 10).map((task) => (
        <article key={task.id} className="task-item">
          <div className="task-item-header">
            <div>
              <strong>{task.title}</strong>
              <span>
                {task.project?.display_name ??
                  task.project?.name ??
                  `Project ${task.project_id}`}
              </span>
            </div>
            <div className="task-badges">
              <span className={`badge ${priorityTone(task.priority)}`}>
                {task.priority}
              </span>
              <span className="badge neutral">{task.type}</span>
              <span className="badge neutral">
                score {task.priority_score ?? "-"}
              </span>
            </div>
          </div>
          <p>{task.description}</p>
          <div className="task-actions">
            {task.status !== "doing" ? (
              <button
                type="button"
                className="button primary small"
                onClick={() => void onTaskStatusChange(task.id, "doing")}
              >
                Start Fix
              </button>
            ) : null}
            <button
              type="button"
              className="button secondary small"
              onClick={() => void onTaskStatusChange(task.id, "done")}
            >
              Mark Done
            </button>
            <select
              value={task.status}
              aria-label="Change task status"
              onChange={(event) =>
                void onTaskStatusChange(task.id, event.target.value)
              }
            >
              <option value="todo">Todo</option>
              <option value="doing">Doing</option>
              <option value="blocked">Blocked</option>
              <option value="done">Done</option>
              <option value="ignored">Ignored</option>
            </select>
          </div>
        </article>
      ))}
      {tasks.length === 0 ? (
        <div className="empty-state">No open tasks.</div>
      ) : null}
    </div>
  </section>
);
