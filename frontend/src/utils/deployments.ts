import type { DeploymentRecord } from "../types";

export const visibleDeploymentEnvironments = ["preview", "production"] as const;

export const deploymentLabels: Record<string, string> = {
  preview: "Local preview",
  production: "Remote production",
};

export const formatDeploymentDate = (
  value: string | null | undefined,
): string => {
  if (!value) {
    return "Not recorded";
  }

  return new Date(value.replace(" ", "T")).toLocaleString();
};

export const deploymentPath = (deployment: DeploymentRecord): string => {
  return (
    deployment.remote_path ??
    deployment.destination_path ??
    deployment.source_path ??
    "No path recorded"
  );
};
