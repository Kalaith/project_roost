import type { ApiEnvelope, BugReport, ProjectTask } from "../types";
import api from "./client";

const unwrap = <T>(envelope: ApiEnvelope<T>): T => {
  if (!envelope.success || envelope.data === undefined) {
    throw new Error(envelope.message ?? "Project Roost API request failed.");
  }

  return envelope.data;
};

export const getBugReports = async (
  status = "new",
): Promise<BugReport[]> => {
  const response = await api.get<ApiEnvelope<{ reports: BugReport[] }>>(
    "/bug-reports",
    {
      params: { status },
    },
  );

  return unwrap(response.data).reports;
};

export type BugReportModeration =
  | { action: "approve"; priority?: "low" | "medium" | "high" }
  | { action: "reject" }
  | { action: "spam" };

export const moderateBugReport = async (
  id: number,
  payload: BugReportModeration,
): Promise<{ report: BugReport; task?: ProjectTask }> => {
  const response = await api.patch<
    ApiEnvelope<{ report: BugReport; task?: ProjectTask }>
  >(`/bug-reports/${id}`, payload);

  return unwrap(response.data);
};
