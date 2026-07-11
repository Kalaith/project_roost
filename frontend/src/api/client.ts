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

let getAuthToken: (() => string | null) | null = null;

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

// A 401 from any route must NEVER clear the session or log the user out.
// Project Roost renders public data even when unauthenticated, and the user's
// identity is owned by the WebHatchery login — not by whether one API call was
// authorized. Auth failures are propagated to the caller (which surfaces an
// error) and the session is left untouched.
api.interceptors.response.use(
  (response) => response,
  (error) => Promise.reject(error),
);

export default api;
