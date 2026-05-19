import { create } from "zustand";
import { persist } from "zustand/middleware";
import type { ProjectFilters } from "../types";

interface ProjectFilterState {
  filters: ProjectFilters;
  setFilter: <K extends keyof ProjectFilters>(
    key: K,
    value: ProjectFilters[K],
  ) => void;
  resetFilters: () => void;
}

const defaultFilters: ProjectFilters = {
  search: "",
  category: "all",
  status: "all",
  shape: "all",
  risk: "all",
  sort: "lowest_overall",
};

export const useProjectFilters = create<ProjectFilterState>()(
  persist(
    (set) => ({
      filters: defaultFilters,
      setFilter: (key, value) =>
        set((state) => ({
          filters: {
            ...state.filters,
            [key]: value,
          },
        })),
      resetFilters: () => set({ filters: defaultFilters }),
    }),
    {
      name: "project-roost-filters",
    },
  ),
);
