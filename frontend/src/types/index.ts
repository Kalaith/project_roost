export interface User {
  id: number | string;
  email?: string;
  username?: string;
  display_name?: string;
  role?: string;
  is_guest?: boolean;
  auth_type?: "frontpage" | "guest";
}

export interface HealthResponse {
  status: string;
  timestamp: string;
  service: string;
  version: string;
}

export interface ApiEnvelope<T> {
  success: boolean;
  data?: T;
  message?: string;
  login_url?: string;
}

export interface ReviewSnapshot {
  id: number;
  project_id?: number;
  reviewed_at: string;
  source: string;
  frontend_score: number | null;
  backend_score: number | null;
  security_score: number | null;
  overall_score: number | null;
  notes: string | null;
  priority_fix: string | null;
}

export interface RiskAssessment {
  severity: string;
  auth_risk: string;
  data_risk: string;
  env_risk: string;
  ownership_risk: string;
  notes: string;
}

export interface Project {
  id: number;
  slug: string;
  name: string;
  display_name: string;
  category: string;
  status: string;
  stage: string;
  shape: string;
  summary: string;
  repo_path: string | null;
  preview_url: string | null;
  production_url: string | null;
  version: string;
  group_name: string;
  repository_type: string | null;
  repository_url: string | null;
  hidden: boolean;
  show_on_homepage: boolean;
  archived: boolean;
  created_at: string | null;
  updated_at: string | null;
  latest_review: ReviewSnapshot | null;
  risk: RiskAssessment;
}

export interface ProjectTask {
  id: number;
  project_id: number;
  title: string;
  description: string;
  type: string;
  priority: string;
  status: string;
  effort: string;
  impact: string;
  source: string | null;
  created_at: string | null;
  updated_at: string | null;
  project?: {
    id: number;
    slug: string;
    name: string;
    display_name?: string;
    category: string;
  };
  risk_severity?: string;
  overall_score?: number | null;
  priority_score?: number;
}

export interface BugReport {
  id: number;
  project_id: number | null;
  project_slug: string;
  summary: string;
  description: string;
  contact: string | null;
  game_version: string | null;
  page_url: string | null;
  user_agent: string | null;
  status: string;
  promoted_task_id: number | null;
  created_at: string | null;
  reviewed_at: string | null;
}

export interface DeploymentRecord {
  id: number;
  project_id: number | null;
  project_slug: string;
  environment: string;
  target_type: string;
  status: string;
  frontend_deployed: boolean;
  backend_deployed: boolean;
  destination_path: string | null;
  remote_path: string | null;
  source_path: string | null;
  publish_mode: string;
  git_commit: string | null;
  actor: string | null;
  notes: string | null;
  deployed_at: string;
  created_at: string | null;
}

export interface DeploymentHistory {
  deployments: DeploymentRecord[];
  latest: Record<string, DeploymentRecord | null>;
}

export interface DashboardSummary {
  total_projects: number;
  average_overall: number | null;
  high_risk_projects: number;
  categories: Record<string, number>;
  tasks: Record<string, number>;
  deployments: Record<string, DeploymentRecord | null>;
}

export interface ProjectFilters {
  search: string;
  category: string;
  status: string;
  shape: string;
  risk: string;
  sort: string;
}

export interface ProjectUpdatePayload {
  name?: string;
  display_name?: string | null;
  slug?: string;
  status?: string;
  stage?: string;
  category?: string;
  shape?: string;
  summary?: string;
  repo_path?: string | null;
  path?: string | null;
  preview_url?: string | null;
  production_url?: string | null;
  version?: string;
  group_name?: string;
  repository_type?: string | null;
  repository_url?: string | null;
  hidden?: boolean;
  show_on_homepage?: boolean;
  archived?: boolean;
  risk?: Partial<RiskAssessment>;
}

export type ProjectCreatePayload = Required<
  Pick<ProjectUpdatePayload, "name" | "status" | "stage" | "category" | "shape">
> &
  Omit<
    ProjectUpdatePayload,
    "name" | "status" | "stage" | "category" | "shape"
  >;

export interface ProjectDiscoveryCandidate extends ProjectCreatePayload {
  source: string;
  display_name: string;
  confidence: number;
}
