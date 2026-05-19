import { create } from "zustand";
import { persist } from "zustand/middleware";
import {
  registerAuthTokenResolver,
  registerUnauthorizedCallback,
} from "../api/client";
import type { User } from "../types";

interface AuthState {
  user: User | null;
  token: string | null;
  setAuth: (user: User, token: string) => void;
  logout: () => void;
}

const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      setAuth: (user, token) => set({ user, token }),
      logout: () => set({ user: null, token: null }),
    }),
    {
      name: "auth-storage",
    },
  ),
);

registerUnauthorizedCallback(() => {
  useAuthStore.getState().logout();
});

registerAuthTokenResolver(() => useAuthStore.getState().token);

export { useAuthStore };
