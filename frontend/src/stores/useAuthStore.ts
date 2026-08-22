import { create } from "zustand";
import { registerAuthTokenResolver } from "../api/client";
import { readFrontpageToken, readFrontpageUser } from "@webhatchery/auth-react";
import type { User } from "../types";

interface AuthState {
  user: User | null;
  token: string | null;
  setAuth: (user: User, token: string) => void;
  logout: () => void;
}

const useAuthStore = create<AuthState>()(
  (set) => ({
    user: readFrontpageUser() as User | null,
    token: readFrontpageToken(),
    setAuth: (user, token) => set({ user, token }),
    logout: () => set({ user: null, token: null }),
  }),
);

registerAuthTokenResolver(() => useAuthStore.getState().token ?? readFrontpageToken());

export { useAuthStore };
