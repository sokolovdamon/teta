import "server-only";
import { serverApi } from "@/lib/server";

/** CMS page of stream G1: GET /api/v1/cms/pages/{slug} → markdown body; 404 → the built-in default content. */
export type CmsPage = { slug: string; title: string; body: string; seo_title: string | null; seo_description: string | null };

export async function getCmsPage(slug: string): Promise<CmsPage | null> {
  try {
    const res = await serverApi<{ data: CmsPage }>(`/cms/pages/${encodeURIComponent(slug)}`, { auth: false, revalidate: 300 });
    return res?.data?.body ? res.data : null;
  } catch {
    return null;
  }
}

/** Public settings of this instance (INSTANCE): name, legal name, support email, emergency phone. */
export type InstancePublic = {
  name: string;
  legal_name: string | null;
  domain: string | null;
  site_url: string | null;
  support_email: string | null;
  emergency_phone: string | null;
};

export const INSTANCE_FALLBACK: InstancePublic = {
  name: "ТЕТА",
  legal_name: "ИП Иващенко",
  domain: "teta.su",
  site_url: null,
  support_email: "support@teta.su",
  emergency_phone: "112",
};

export async function getInstance(): Promise<InstancePublic> {
  try {
    const res = await serverApi<{ data: Partial<InstancePublic> }>("/instance", { auth: false, revalidate: 300 });
    return { ...INSTANCE_FALLBACK, ...(res?.data ?? {}) };
  } catch {
    return INSTANCE_FALLBACK;
  }
}

/** Public legal documents (CONSENT, SITE-17). */
export type LegalListItem = { slug: string; title: string; kind: string; version: string; published_at: string };
export type LegalDocument = LegalListItem & { body: string };

export async function getLegalList(): Promise<LegalListItem[] | null> {
  try {
    const res = await serverApi<{ data: LegalListItem[] }>("/legal", { auth: false, revalidate: 300 });
    return res?.data ?? [];
  } catch {
    return null;
  }
}

export async function getLegalDocument(slug: string): Promise<LegalDocument | null | "error"> {
  try {
    const res = await serverApi<{ data: LegalDocument }>(`/legal/${encodeURIComponent(slug)}`, { auth: false, revalidate: 300 });
    return res?.data ?? null;
  } catch {
    return "error";
  }
}
