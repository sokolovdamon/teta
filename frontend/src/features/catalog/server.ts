import "server-only";
import { serverApi } from "@/lib/server";
import type { CatalogFilters } from "./query";
import { filtersToApiQuery } from "./query";
import type { CatalogResponse, Dictionaries, ProfileResponse, RequestLanding } from "./types";

/** Result of a public request: data, "not found" or a temporary failure (the page shows an error state). */
export type Loaded<T> = { status: "ok"; data: T } | { status: "not_found" } | { status: "error" };

async function load<T>(path: string, query?: Parameters<typeof serverApi>[1]): Promise<Loaded<T>> {
  try {
    const res = await serverApi<T>(path, { auth: false, ...query });
    return res === null ? { status: "not_found" } : { status: "ok", data: res };
  } catch {
    return { status: "error" };
  }
}

export async function getDictionaries(): Promise<Dictionaries | null> {
  const res = await load<{ data: Dictionaries }>("/dictionaries", { revalidate: 300 });
  return res.status === "ok" ? res.data.data : null;
}

export function getCatalog(filters: CatalogFilters, perPage = 12): Promise<Loaded<CatalogResponse>> {
  return load<CatalogResponse>("/psychologists", { query: filtersToApiQuery(filters, perPage), revalidate: 30 });
}

export async function getProfile(slug: string): Promise<Loaded<ProfileResponse>> {
  const res = await load<{ data: ProfileResponse }>(`/psychologists/${encodeURIComponent(slug)}`, { revalidate: 60 });
  return res.status === "ok" ? { status: "ok", data: res.data.data } : res;
}

export async function getRequestLanding(slug: string, format: "individual" | "pair"): Promise<Loaded<RequestLanding>> {
  const res = await load<{ data: RequestLanding }>(`/dictionaries/requests/${encodeURIComponent(slug)}`, { query: { format }, revalidate: 300 });
  return res.status === "ok" ? { status: "ok", data: res.data.data } : res;
}
