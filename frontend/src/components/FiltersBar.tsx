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
}

export const FiltersBar: React.FC<FiltersBarProps> = ({
  filters,
  onFilterChange,
  onRefresh,
  onReset,
  loading,
}) => (
  <section className="toolbar" aria-label="Project filters">
    <label className="field search-field">
      <span>Search</span>
      <input
        value={filters.search}
        onChange={(event) => onFilterChange("search", event.target.value)}
        placeholder="Name, notes, fix"
      />
    </label>
    <label className="field">
      <span>Category</span>
      <select
        value={filters.category}
        onChange={(event) => onFilterChange("category", event.target.value)}
      >
        <option value="all">All</option>
        {PROJECT_CATEGORY_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
    <label className="field">
      <span>Status</span>
      <select
        value={filters.status}
        onChange={(event) => onFilterChange("status", event.target.value)}
      >
        <option value="all">All</option>
        {PROJECT_STATUS_OPTIONS.map((option) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </select>
    </label>
    <label className="field">
      <span>Risk</span>
      <select
        value={filters.risk}
        onChange={(event) => onFilterChange("risk", event.target.value)}
      >
        <option value="all">All</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
      </select>
    </label>
    <label className="field">
      <span>Sort</span>
      <select
        value={filters.sort}
        onChange={(event) => onFilterChange("sort", event.target.value)}
      >
        <option value="lowest_overall">Lowest score</option>
        <option value="highest_risk">Highest risk</option>
        <option value="recently_reviewed">Recent review</option>
        <option value="name">Name</option>
      </select>
    </label>
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
  </section>
);
