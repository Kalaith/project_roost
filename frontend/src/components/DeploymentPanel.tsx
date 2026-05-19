import React from "react";
import type { DeploymentRecord } from "../types";
import {
  deploymentLabels,
  formatDeploymentDate,
  visibleDeploymentEnvironments,
} from "../utils/deployments";

interface DeploymentPanelProps {
  deployments: Record<string, DeploymentRecord | null> | undefined;
}

export const DeploymentPanel: React.FC<DeploymentPanelProps> = ({
  deployments,
}) => (
  <section className="panel deployment-panel" aria-label="Publish tracking">
    <div className="panel-header">
      <h2>Publish Tracking</h2>
      <span>Preview and production</span>
    </div>
    <div className="deployment-stack">
      {visibleDeploymentEnvironments.map((environment) => {
        const label = deploymentLabels[environment];
        const deployment = deployments?.[environment] ?? null;

        return (
          <article key={environment} className="deployment-item">
            <div>
              <strong>{label}</strong>
              <span>{formatDeploymentDate(deployment?.deployed_at)}</span>
            </div>
            {deployment ? (
              <p>
                {deployment.project_slug} via {deployment.publish_mode} (
                {deployment.status})
              </p>
            ) : (
              <p>No publish recorded.</p>
            )}
          </article>
        );
      })}
    </div>
  </section>
);
