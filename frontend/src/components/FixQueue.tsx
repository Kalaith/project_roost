import React from "react";
import type { ProjectTask } from "../types";

interface FixQueueProps {
  tasks: ProjectTask[];
  onTaskStatusChange: (id: number, status: string) => Promise<void>;
}

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
          <div>
            <strong>{task.title}</strong>
            <span>{task.project?.name ?? `Project ${task.project_id}`}</span>
          </div>
          <p>{task.description}</p>
          <div className="task-meta">
            <span>{task.priority}</span>
            <span>{task.type}</span>
            <span>score {task.priority_score ?? "-"}</span>
            <select
              value={task.status}
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
