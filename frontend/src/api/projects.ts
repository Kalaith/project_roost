import type {
  ApiEnvelope,
  DashboardSummary,
  DeploymentHistory,
  Project,
  ProjectCreatePayload,
  ProjectDiscoveryCandidate,
  ProjectFilters,
  ProjectTask,
  ProjectUpdatePayload,
} from "../types";
import api from "./client";

const unwrap = <T>(envelope: ApiEnvelope<T>): T => {
  if (!envelope.success || envelope.data === undefined) {
    throw new Error(envelope.message ?? "Project Roost API request failed.");
  }

  return envelope.data;
};

export const listProjects = async (
  filters: ProjectFilters,
): Promise<Project[]> => {
  const response = await api.get<ApiEnvelope<{ projects: Project[] }>>(
    "/projects",
    {
      params: filters,
    },
  );

  return unwrap(response.data).projects;
};

export const getDashboardSummary = async (): Promise<DashboardSummary> => {
  const response =
    await api.get<ApiEnvelope<{ summary: DashboardSummary }>>(
      "/dashboard/summary",
    );

  return unwrap(response.data).summary;
};

export const getProjectDeployments = async (
  projectSlug: string,
  limit = 8,
): Promise<DeploymentHistory> => {
  const response = await api.get<ApiEnvelope<DeploymentHistory>>(
    "/deployments",
    {
      params: {
        project: projectSlug,
        limit,
      },
    },
  );

  return unwrap(response.data);
};

export const getFixQueue = async (): Promise<ProjectTask[]> => {
  const response = await api.get<ApiEnvelope<{ tasks: ProjectTask[] }>>(
    "/dashboard/fix-queue",
  );

  return unwrap(response.data).tasks;
};

export const updateProject = async (
  id: number,
  payload: ProjectUpdatePayload,
): Promise<Project> => {
  const response = await api.patch<ApiEnvelope<{ project: Project }>>(
    `/projects/${id}`,
    payload,
  );

  return unwrap(response.data).project;
};

export const createProject = async (
  payload: ProjectCreatePayload,
): Promise<Project> => {
  const response = await api.post<ApiEnvelope<{ project: Project }>>(
    "/projects",
    payload,
  );

  return unwrap(response.data).project;
};

export const deleteProject = async (id: number): Promise<void> => {
  const response = await api.delete<ApiEnvelope<null>>(`/projects/${id}`);

  if (!response.data.success) {
    throw new Error(
      response.data.message ?? "Project Roost API request failed.",
    );
  }
};

export const discoverProjects = async (params: {
  search?: string;
  source?: string;
}): Promise<ProjectDiscoveryCandidate[]> => {
  const response = await api.get<
    ApiEnvelope<{ candidates: ProjectDiscoveryCandidate[] }>
  >("/projects/discover", {
    params,
  });

  return unwrap(response.data).candidates;
};

export const updateTask = async (
  id: number,
  payload: Partial<ProjectTask>,
): Promise<ProjectTask> => {
  const response = await api.patch<ApiEnvelope<{ task: ProjectTask }>>(
    `/tasks/${id}`,
    payload,
  );

  return unwrap(response.data).task;
};
