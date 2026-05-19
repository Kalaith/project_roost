import React from "react";
import type { ImportSource } from "../types";

interface ImportPanelProps {
  importing: boolean;
  message: string;
  onImport: (source: ImportSource) => Promise<void>;
}

export const ImportPanel: React.FC<ImportPanelProps> = ({
  importing,
  message,
  onImport,
}) => (
  <section className="panel import-panel" aria-label="Summary imports">
    <div className="panel-header">
      <h2>Review Import</h2>
      <span>{importing ? "Working" : "Ready"}</span>
    </div>
    <div className="import-actions">
      <button
        type="button"
        className="button secondary"
        onClick={() => void onImport("apps")}
        disabled={importing}
      >
        Import Apps
      </button>
      <button
        type="button"
        className="button secondary"
        onClick={() => void onImport("games")}
        disabled={importing}
      >
        Import Games
      </button>
      <button
        type="button"
        className="button secondary"
        onClick={() => void onImport("rust-games")}
        disabled={importing}
      >
        Import Rust Games
      </button>
    </div>
    {message ? <p className="status-line">{message}</p> : null}
  </section>
);
