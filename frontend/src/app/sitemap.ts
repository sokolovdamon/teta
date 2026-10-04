import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/config";
import { serverApi } from "@/lib/server";
import type { CatalogResponse, Dictionaries } from "@/features/catalog/types";

/** X-12: sitemap — static pages, psychologists, the 43 request landings and sections of other modules if present. */
export const revalidate = 3600;

const STATIC: { path: string; priority: number; changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"] }[] = [
  { path: "/", priority: 1, changeFrequency: "daily" },
  { path: "/psychologists", priority: 0.9, changeFrequency: "daily" },
  { path: "/help", priority: 0.8, changeFrequency: "weekly" },
  { path: "/help/para", priority: 0.7, changeFrequency: "weekly" },
  { path: "/how-it-works", priority: 0.7, changeFrequency: "monthly" },
  { path: "/prices", priority: 0.7, changeFrequency: "monthly" },
  { path: "/for-psychologists", priority: 0.6, changeFrequency: "monthly" },
  { path: "/business", priority: 0.6, changeFrequency: "monthly" },
  { path: "/about", priority: 0.5, changeFrequency: "monthly" },
  { path: "/faq", priority: 0.5, changeFrequency: "monthly" },
  { path: "/help-now", priority: 0.5, changeFrequency: "monthly" },
  { path: "/contacts", priority: 0.4, changeFrequency: "yearly" },
  { path: "/legal", priority: 0.3, changeFrequency: "monthly" },
];

const url = (path: string) => `${SITE_URL.replace(/\/$/, "")}${path}`;

async function safe<T>(fn: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await fn();
  } catch {
    return fallback;
  }
}

async function psychologists(): Promise<MetadataRoute.Sitemap> {
  const out: MetadataRoute.Sitemap = [];
  for (let page = 1; page <= 50; page++) {
    const res = await serverApi<CatalogResponse>("/psychologists", { auth: false, revalidate: 3600, query: { per_page: 48, page, sort: "experience" } });
    if (!res) break;
    for (const p of res.data) out.push({ url: url(`/psychologists/${p.slug}`), changeFrequency: "weekly", priority: 0.8 });
    if (page >= res.meta.last_page) break;
  }
  return out;
}

async function landings(): Promise<MetadataRoute.Sitemap> {
  const res = await serverApi<{ data: Dictionaries }>("/dictionaries", { auth: false, revalidate: 3600 });
  return (res?.data.request_groups ?? []).flatMap((g) => g.requests.map((r) => ({ url: url(r.path), changeFrequency: "monthly" as const, priority: 0.7 })));
}

async function legal(): Promise<MetadataRoute.Sitemap> {
  const res = await serverApi<{ data: { slug: string; published_at?: string }[] }>("/legal", { auth: false, revalidate: 3600 });
  return (res?.data ?? []).map((d) => ({ url: url(`/legal/${d.slug}`), lastModified: d.published_at, changeFrequency: "yearly" as const, priority: 0.2 }));
}

type ExternalEntry = { path?: string; url?: string; loc?: string; updated_at?: string; lastModified?: string; lastmod?: string; priority?: number };

/** Sections of other modules (articles, knowledge base tests) publish their own lists; missing endpoints are ignored. */
async function external(path: string): Promise<MetadataRoute.Sitemap> {
  const res = await serverApi<{ data: ExternalEntry[] } | ExternalEntry[]>(path, { auth: false, revalidate: 3600 });
  const items = Array.isArray(res) ? res : (res?.data ?? []);
  return items
    .map((e) => {
      const target = e.url ?? e.loc ?? e.path;
      if (!target) return null;
      return {
        url: target.startsWith("http") ? target : url(target.startsWith("/") ? target : `/${target}`),
        lastModified: e.lastModified ?? e.updated_at ?? e.lastmod,
        priority: e.priority ?? 0.6,
      };
    })
    .filter((e): e is NonNullable<typeof e> => e !== null);
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [people, requests, docs, content, kb] = await Promise.all([
    safe(psychologists, []),
    safe(landings, []),
    safe(legal, []),
    safe(() => external("/content/sitemap"), []),
    safe(() => external("/kb/sitemap"), []),
  ]);
  return [
    ...STATIC.map((s) => ({ url: url(s.path), changeFrequency: s.changeFrequency, priority: s.priority })),
    ...requests,
    ...people,
    ...content,
    ...kb,
    ...docs,
  ];
}
