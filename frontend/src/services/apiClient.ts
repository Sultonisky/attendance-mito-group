const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ?? "http://127.0.0.1:8000/api/v1";

const API_ORIGIN = new URL(API_BASE_URL).origin;

/**
 * Structured API error preserving the HTTP status and Laravel validation
 * errors. Never contains stack traces or framework internals.
 */
export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }
}

/**
 * Read the XSRF-TOKEN cookie set by Laravel Sanctum so mutating requests can
 * send the X-XSRF-TOKEN header. The token is never persisted anywhere; it is
 * only read from the session cookie.
 */
function readXsrfToken(): string | null {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

  return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Initialize the CSRF cookie (GET /sanctum/csrf-cookie).
 *
 * Must be called before the first mutating request of a session.
 */
export async function fetchCsrfCookie(): Promise<void> {
  await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
  });
}

export async function apiFetch<T>(
  endpoint: string,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...((options.headers as Record<string, string>) ?? {}),
  };

  if (options.body && !headers["Content-Type"]) {
    headers["Content-Type"] = "application/json";
  }

  // CSRF protection for state-changing requests (first-party SPA).
  if (["POST", "PUT", "PATCH", "DELETE"].includes(method)) {
    const token = readXsrfToken();
    if (token) {
      headers["X-XSRF-TOKEN"] = token;
    }
  }

  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers,
    credentials: "include",
  });

  if (!response.ok) {
    let message = `API request failed: ${response.status}`;
    let errors: Record<string, string[]> = {};

    try {
      const body = await response.json();
      if (typeof body?.message === "string") {
        message = body.message;
      }
      if (body?.errors && typeof body.errors === "object") {
        errors = body.errors;
      }
    } catch {
      // Non-JSON error body; keep the generic message.
    }

    throw new ApiError(response.status, message, errors);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}
