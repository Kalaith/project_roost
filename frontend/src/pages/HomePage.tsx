import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  getDashboardSummary,
  getFixQueue,
  getProjectDeployments,
  createProject,
  discoverProjects,
  importHtmlSummary,
  listProjects,
  updateProject,
  updateTask,
} from "../api/projects";
import { DashboardCards } from "../components/DashboardCards";
import { DeploymentPanel } from "../components/DeploymentPanel";
import { FiltersBar } from "../components/FiltersBar";
import { FixQueue } from "../components/FixQueue";
import { ImportPanel } from "../components/ImportPanel";
import { ProjectCreatePanel } from "../components/ProjectCreatePanel";
import { ProjectDetail } from "../components/ProjectDetail";
import { ProjectTable } from "../components/ProjectTable";
import { useAuthStore } from "../stores/useAuthStore";
import { useProjectFilters } from "../stores/useProjectFilters";
import type {
  DashboardSummary,
  DeploymentRecord,
  ImportSource,
  Project,
  ProjectCreatePayload,
  ProjectDiscoveryCandidate,
  ProjectTask,
  ProjectUpdatePayload,
  User,
} from "../types";

interface ApiErrorLike {
  response?: {
    data?: {
      message?: string;
      login_url?: string;
    };
  };
}

const errorMessage = (error: unknown): string => {
  const apiError = error as ApiErrorLike;
  const message = apiError.response?.data?.message;
  const loginUrl = apiError.response?.data?.login_url;

  if (message && loginUrl) {
    return `${message} Login: ${loginUrl}`;
  }

  if (message) {
    return message;
  }

  return error instanceof Error ? error.message : "Request failed.";
};

const getUserLabel = (user: User | null): string => {
  if (!user) {
    return "";
  }

  return (
    user.username?.trim() ||
    user.display_name?.trim() ||
    user.email?.trim() ||
    `User ${user.id}`
  );
};

export const HomePage: React.FC = () => {
  const { filters, setFilter, resetFilters } = useProjectFilters();
  const authUser = useAuthStore((state) => state.user);
  const [projects, setProjects] = useState<Project[]>([]);
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [tasks, setTasks] = useState<ProjectTask[]>([]);
  const [selectedDeployments, setSelectedDeployments] = useState<
    DeploymentRecord[]
  >([]);
  const [selectedDeploymentLatest, setSelectedDeploymentLatest] = useState<
    Record<string, DeploymentRecord | null>
  >({});
  const [discoveries, setDiscoveries] = useState<ProjectDiscoveryCandidate[]>(
    [],
  );
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [deploymentsLoading, setDeploymentsLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [creating, setCreating] = useState(false);
  const [discovering, setDiscovering] = useState(false);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState("");
  const [importMessage, setImportMessage] = useState("");
  const [discoveryMessage, setDiscoveryMessage] = useState("");
  const authUserLabel = getUserLabel(authUser);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const [nextSummary, nextProjects, nextTasks] = await Promise.all([
        getDashboardSummary(),
        listProjects(filters),
        getFixQueue(),
      ]);

      setSummary(nextSummary);
      setProjects(nextProjects);
      setTasks(nextTasks);
      setSelectedId((current) => {
        if (
          current !== null &&
          nextProjects.some((project) => project.id === current)
        ) {
          return current;
        }

        return nextProjects[0]?.id ?? null;
      });
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, [filters]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const selectedProject = useMemo(
    () => projects.find((project) => project.id === selectedId) ?? null,
    [projects, selectedId],
  );

  const selectedSlug = selectedProject?.slug ?? "";

  useEffect(() => {
    if (selectedSlug === "") {
      setSelectedDeployments([]);
      setSelectedDeploymentLatest({});
      return;
    }

    let active = true;
    setDeploymentsLoading(true);

    getProjectDeployments(selectedSlug)
      .then((history) => {
        if (!active) {
          return;
        }

        setSelectedDeployments(history.deployments);
        setSelectedDeploymentLatest(history.latest);
      })
      .catch((caught: unknown) => {
        if (!active) {
          return;
        }

        setSelectedDeployments([]);
        setSelectedDeploymentLatest({});
        setError(errorMessage(caught));
      })
      .finally(() => {
        if (active) {
          setDeploymentsLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [selectedSlug]);

  const saveProject = async (id: number, payload: ProjectUpdatePayload) => {
    setSaving(true);
    setError("");

    try {
      const updated = await updateProject(id, payload);
      setProjects((current) =>
        current.map((project) => (project.id === id ? updated : project)),
      );
      setSelectedId(updated.id);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setSaving(false);
    }
  };

  const addProject = async (payload: ProjectCreatePayload) => {
    setCreating(true);
    setError("");

    try {
      const created = await createProject(payload);
      setProjects((current) => [created, ...current]);
      setSelectedId(created.id);
      setDiscoveries((current) =>
        current.filter((candidate) => candidate.slug !== payload.slug),
      );
      await loadData();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setCreating(false);
    }
  };

  const discoverNewProjects = async (params: {
    search?: string;
    source?: string;
  }) => {
    setDiscovering(true);
    setDiscoveryMessage("");
    setError("");

    try {
      const candidates = await discoverProjects(params);
      setDiscoveries(candidates);
      setDiscoveryMessage(
        candidates.length === 0
          ? "No new project folders found."
          : `${candidates.length} new project folder${candidates.length === 1 ? "" : "s"} found.`,
      );
    } catch (caught) {
      setDiscoveries([]);
      setError(errorMessage(caught));
    } finally {
      setDiscovering(false);
    }
  };

  const importSource = async (source: ImportSource) => {
    setImporting(true);
    setImportMessage("");
    setError("");

    try {
      const result = await importHtmlSummary(source);
      setImportMessage(
        `${result.project_count} projects, ${result.review_count} reviews, ${result.task_count} tasks imported.`,
      );
      await loadData();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setImporting(false);
    }
  };

  const changeTaskStatus = async (id: number, status: string) => {
    setError("");

    try {
      const updated = await updateTask(id, { status });
      setTasks((current) =>
        current.map((task) =>
          task.id === id ? { ...task, ...updated } : task,
        ),
      );
    } catch (caught) {
      setError(errorMessage(caught));
    }
  };

  return (
    <main className="app-shell">
      <header className="app-header">
        <div>
          <span className="eyebrow">WebHatchery</span>
          <h1>Project Roost</h1>
        </div>
        <div
          className="header-status"
          title={authUserLabel ? `Signed in as ${authUserLabel}` : undefined}
        >
          <span>{loading ? "Loading" : "Ready"}</span>
          {authUserLabel ? (
            <>
              <span className="header-status-divider" aria-hidden="true" />
              <span className="auth-indicator">
                <span className="auth-dot" aria-hidden="true" />
                <span className="auth-label">Signed in</span>
                <strong className="header-user">{authUserLabel}</strong>
              </span>
            </>
          ) : null}
        </div>
      </header>

      {error ? <div className="error-banner">{error}</div> : null}

      <DashboardCards summary={summary} />
      <FiltersBar
        filters={filters}
        onFilterChange={setFilter}
        onRefresh={() => void loadData()}
        onReset={resetFilters}
        loading={loading}
      />
      <ProjectCreatePanel
        creating={creating}
        discoveries={discoveries}
        discovering={discovering}
        discoveryMessage={discoveryMessage}
        onCreate={addProject}
        onDiscover={discoverNewProjects}
      />

      <div className="workspace-grid">
        <ProjectTable
          projects={projects}
          selectedId={selectedId}
          onSelect={(project) => setSelectedId(project.id)}
        />
        <ProjectDetail
          project={selectedProject}
          deployments={selectedDeployments}
          deploymentLatest={selectedDeploymentLatest}
          deploymentsLoading={deploymentsLoading}
          saving={saving}
          onSave={saveProject}
        />
      </div>

      <div className="lower-grid">
        <FixQueue tasks={tasks} onTaskStatusChange={changeTaskStatus} />
        <DeploymentPanel deployments={summary?.deployments} />
        <ImportPanel
          importing={importing}
          message={importMessage}
          onImport={importSource}
        />
      </div>
    </main>
  );
};
