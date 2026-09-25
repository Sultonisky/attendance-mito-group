const configuredApiBaseUrl =
  import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8000/api/v1";

function resolveApiBaseUrl(): string {
  const apiUrl = new URL(configuredApiBaseUrl);
  const browserHost = window.location.hostname;
  const isLocalHost = (host: string) =>
    host === "localhost" || host === "127.0.0.1";

  // Cookies are host-scoped, so localhost and 127.0.0.1 must not be mixed.
  if (isLocalHost(browserHost) && isLocalHost(apiUrl.hostname)) {
    apiUrl.hostname = browserHost;
  }

  return apiUrl.toString().replace(/\/$/, "");
}

const API_BASE_URL = resolveApiBaseUrl();

const API_ORIGIN = new URL(API_BASE_URL).origin;
let csrfCookiePromise: Promise<void> | null = null;

/**
 * Soft UX redirects for session expiry / maintenance.
 * Never blocks the thrown ApiError — callers still handle locally.
 */
async function notifyGlobalHttpError(response: Response): Promise<void> {
  if (response.status === 401) {
    const { navigateOnSessionExpired } = await import("./httpErrorNavigation");
    await navigateOnSessionExpired();
    return;
  }

  if (response.status === 503) {
    const { navigateOnMaintenance } = await import("./httpErrorNavigation");
    await navigateOnMaintenance(response);
  }
}

/**
 * Structured API error preserving the HTTP status and Laravel validation
 * errors. Never contains stack traces or framework internals.
 */
export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;

  constructor(
    status: number,
    message: string,
    errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }
}

/** First Laravel validation message, if any (422 body.errors). */
export function firstValidationMessage(err: unknown): string | null {
  if (!(err instanceof ApiError)) return null;
  for (const messages of Object.values(err.errors)) {
    if (Array.isArray(messages) && typeof messages[0] === "string" && messages[0]) {
      return messages[0];
    }
  }
  return null;
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
  if (!csrfCookiePromise) {
    csrfCookiePromise = fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
      method: "GET",
      credentials: "include",
    })
      .then((response) => {
        if (!response.ok) {
          throw new ApiError(
            response.status,
            "Unable to initialize CSRF protection.",
          );
        }
      })
      .finally(() => {
        csrfCookiePromise = null;
      });
  }

  await csrfCookiePromise;
}

export async function apiFetch<T>(
  endpoint: string,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  if (["POST", "PUT", "PATCH", "DELETE"].includes(method) && !readXsrfToken()) {
    await fetchCsrfCookie();
  }

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

    await notifyGlobalHttpError(response);
    throw new ApiError(response.status, message, errors);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

/**
 * Submit a multipart/form-data request (e.g. file uploads) reusing the same
 * CSRF, credential, and error-handling behavior as apiFetch.
 */
export async function apiFetchFormData<T>(
  endpoint: string,
  formData: FormData,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  if (["POST", "PUT", "PATCH", "DELETE"].includes(method) && !readXsrfToken()) {
    await fetchCsrfCookie();
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...((options.headers as Record<string, string>) ?? {}),
  };

  if (["POST", "PUT", "PATCH", "DELETE"].includes(method)) {
    const token = readXsrfToken();
    if (token) {
      headers["X-XSRF-TOKEN"] = token;
    }
  }

  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers,
    body: formData,
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

    await notifyGlobalHttpError(response);
    throw new ApiError(response.status, message, errors);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}
