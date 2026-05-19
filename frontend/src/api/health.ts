import api from "./client";
import type { HealthResponse } from "../types";

export const fetchHealth = async (): Promise<HealthResponse> => {
  const response = await api.get<HealthResponse>("/health");
  return response.data;
};
