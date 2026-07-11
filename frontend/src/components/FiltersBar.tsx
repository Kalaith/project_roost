import React from "react";
import {
  PROJECT_CATEGORY_OPTIONS,
  PROJECT_STATUS_OPTIONS,
} from "../constants/projectOptions";
import type { ProjectFilters } from "../types";

interface FiltersBarProps {
  filters: ProjectFilters;
  onFilterChange: <K extends keyof ProjectFilters>(
    key: K,
    value: ProjectFilters[K],
  ) => void;
  onRefresh: () => void;
  onReset: () => void;
  loading: boolean;
  shownCount: number;
}

interface ActiveChip {
  key: keyof ProjectFilters;
  label: string;
}

const categoryLabel = (value: string): string =>
  PROJECT_CATEGORY_OPTIONS.find((option) => option.value === value)?.label ??
  value;

export const FiltersBar: React.FC<FiltersBarProps> = ({
  filters,
  onFilterChange,
  onRefresh,
  onReset,
  loading,
  shownCount,
}) => {
  const chips: ActiveChip[] = [];
  if (filters.search.trim() !== "") {
    chips.push({ key: "search", label: `"${filters.search.trim()}"` });
  }
  if (filters.category !== "all") {
    chips.push({ key: "category", label: categoryLabel(filters.category) });
  }
  if (filters.status !== "all") {
    chips.push({ key: "status", label: filters.status });
  }
  if (filters.risk !== "all") {
    chips.push({ key: "risk", label: `${filters.risk} risk` });
  }

  const clearChip = (key: keyof ProjectFilters) => {
    onFilterChange(key, key === "search" ? "" : "all");
  };

  return (
    <section className="toolbar" aria-label="Project filters">
      <div className="toolbar-row">
        <input
          value={filters.search}
          onChange={(event) => onFilterChange("search", event.target.value)}
          placeholder="Search name, notes, fix…"
          aria-label="Search projects"
        />
        <select
          value={filters.category}
          onChange={(event) => onFilterChange("category", event.target.value)}
          aria-label="Filter by category"
        >
          <option value="all">All categories</option>
          {PROJECT_CATEGORY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        <select
          value={filters.status}
          onChange={(event) => onFilterChange("status", event.target.value)}
          aria-label="Filter by status"
        >
          <option value="all">All statuses</option>
          {PROJECT_STATUS_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
        <select
          value={filters.risk}
          onChange={(event) => onFilterChange("risk", event.target.value)}
          aria-label="Filter by risk"
        >
          <option value="all">All risk levels</option>
          <option value="high">High risk</option>
          <option value="medium">Medium risk</option>
          <option value="low">Low risk</option>
        </select>
        <select
          value={filters.sort}
          onChange={(event) => onFilterChange("sort", event.target.value)}
          aria-label="Sort projects"
        >
          <option value="lowest_overall">Lowest score</option>
          <option value="highest_risk">Highest risk</option>
          <option value="recently_reviewed">Recent review</option>
          <option value="name">Name</option>
        </select>
        <div className="toolbar-actions">
          <button type="button" className="button secondary" onClick={onReset}>
            Reset
          </button>
          <button
            type="button"
            className="button primary"
            onClick={onRefresh}
            disabled={loading}
          >
            Refresh
          </button>
        </div>
      </div>
      {chips.length > 0 ? (
        <div className="toolbar-chips">
          <span className="toolbar-count">
            {shownCount} project{shownCount === 1 ? "" : "s"} shown
          </span>
          {chips.map((chip) => (
            <span key={chip.key} className="filter-chip">
              {chip.label}
              <button
                type="button"
                onClick={() => clearChip(chip.key)}
                aria-label={`Clear ${chip.key} filter`}
              >
                ×
              </button>
            </span>
          ))}
        </div>
      ) : null}
    </section>
  );
};
