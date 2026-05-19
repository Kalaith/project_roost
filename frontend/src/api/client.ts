import axios from "axios";

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL;
if (!apiBaseUrl) {
  throw new Error("VITE_API_BASE_URL environment variable is required.");
}

const api = axios.create({
  baseURL: apiBaseUrl,
  headers: {
    "Content-Type": "application/json",
  },
});

let onUnauthorized: (() => void) | null = null;
let getAuthToken: (() => string | null) | null = null;

export const registerUnauthorizedCallback = (callback: () => void) => {
  onUnauthorized = callback;
};

export const registerAuthTokenResolver = (resolver: () => string | null) => {
  getAuthToken = resolver;
};

api.interceptors.request.use((config) => {
  const token = getAuthToken?.() ?? null;

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && onUnauthorized) {
      onUnauthorized();
    }

    return Promise.reject(error);
  },
);

export default api;
