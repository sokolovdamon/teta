import "server-only";
import { cookies } from "next/headers";
import { cache } from "react";
import { BACKEND_URL, TOKEN_COOKIE } from "./config";
import { buildQuery } from "./api";
import type { User } from "./types";

type Options = { query?: Parameters<typeof buildQuery>[0]; revalidate?: number | false; auth?: boolean };

/** Server-side call to Laravel /api/v1. Throws on non-2xx except 404 which returns null. */
export async function serverApi<T>(path: string, { query, revalidate = false, auth = true }: Options = {}): Promise<T | null> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (auth) {
    const token = (await cookies()).get(TOKEN_COOKIE)?.value;
    if (token) headers.Authorization = `Bearer ${token}`;
  }
  const res = await fetch(`${BACKEND_URL}/api/v1${path}${buildQuery(query)}`, {
    headers,
    ...(auth ? { cache: "no-store" as const } : revalidate === false ? { cache: "no-store" as const } : { next: { revalidate } }),
  });
  if (res.status === 404 || res.status === 401 || res.status === 403) return null;
  if (!res.ok) throw new Error(`Backend ${res.status} for ${path}`);
  return (await res.json()) as T;
}

/** Current user for this request (deduplicated), or null for guests. */
export const getCurrentUser = cache(async (): Promise<User | null> => {
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;
  if (!token) return null;
  const res = await serverApi<{ data: User }>("/auth/me");
  return res?.data ?? null;
});
