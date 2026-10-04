/**
 * Browser-side API client. Every call goes through the Next.js BFF (/bff/*),
 * which attaches the token from the httpOnly cookie and forwards to Laravel /api/*.
 */
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
    public payload: unknown = null,
  ) {
    super(message);
  }

  /** First validation message for a field, if any. */
  field(name: string): string | null {
    return this.errors[name]?.[0] ?? null;
  }
}

type Query = Record<string, string | number | boolean | null | undefined | (string | number)[]>;
type Options = { method?: string; body?: unknown; query?: Query; signal?: AbortSignal };

export function buildQuery(query?: Query): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === "") continue;
    if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, String(v)));
    else params.set(key, String(value));
  }
  const s = params.toString();
  return s ? `?${s}` : "";
}

export async function api<T = unknown>(path: string, { method = "GET", body, query, signal }: Options = {}): Promise<T> {
  const isForm = typeof FormData !== "undefined" && body instanceof FormData;
  const res = await fetch(`/bff/v1${path}${buildQuery(query)}`, {
    method,
    signal,
    headers: { Accept: "application/json", ...(body && !isForm ? { "Content-Type": "application/json" } : {}) },
    body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
    credentials: "same-origin",
  });
  const text = await res.text();
  const data = text ? safeJson(text) : null;
  if (!res.ok) {
    const d = (data ?? {}) as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(res.status, d.message ?? "Не удалось выполнить запрос", d.errors ?? {}, data);
  }
  return data as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
