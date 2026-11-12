/**
 * Small json requests of the loan module (contacts created inside a form). Inertia visits are used
 * for everything that changes the page; these requests only return data.
 */

// Laravel sets the cookie XSRF-TOKEN; sent back as header it passes the csrf check
const xsrfToken = () =>
  decodeURIComponent(
    document.cookie
      .split("; ")
      .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
      ?.slice("XSRF-TOKEN=".length) ?? "",
  );

export type JsonResult<T> =
  { ok: true; data: T } | { ok: false; errors: Record<string, string> };

/**
 * Send json and get json back. Validation errors come back as the first message per field.
 */
export const sendJson = async <T>(
  method: "POST" | "PUT",
  url: string,
  data: Record<string, unknown>,
): Promise<JsonResult<T>> => {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": xsrfToken(),
    },
    body: JSON.stringify(data),
  });
  const body = await response.json().catch(() => ({}));
  if (response.ok) {
    return { ok: true, data: body as T };
  }
  const errors: Record<string, string> = {};
  for (const [field, messages] of Object.entries(body.errors ?? {})) {
    errors[field] = (messages as string[])[0];
  }
  if (!Object.keys(errors).length) {
    errors.name = body.message ?? "Das hat nicht geklappt.";
  }
  return { ok: false, errors };
};

/**
 * Get json, null if the request failed.
 */
export const getJson = async <T>(url: string): Promise<T | null> => {
  const response = await fetch(url, {
    headers: { Accept: "application/json" },
  });
  return response.ok ? ((await response.json()) as T) : null;
};

export type Contact = {
  id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  street?: string | null;
  zip?: string | null;
  city?: string | null;
  council_id?: number | null;
  notes?: string | null;
  address?: string;
  affiliation?: string;
  // German names of the fields still missing for a responsible person
  missing?: string[];
};

export type LoanSummary = {
  id: number;
  number: string;
  title: string;
  status: string;
  status_label: string;
  status_variant: "neutral" | "accent" | "success" | "warning" | "danger";
  kind: "external" | "internal";
  pickup_at: string;
  return_at: string;
  overdue_days: number;
  // confirmed, but the return time passed without a pickup
  not_picked_up: boolean;
  responsible: string | null;
};

/**
 * Date as "12.11.2026, 16:00".
 */
export const formatDateTime = (value: string) =>
  new Date(value).toLocaleString("de-DE", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
