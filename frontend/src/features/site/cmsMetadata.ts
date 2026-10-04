import "server-only";
import type { Metadata } from "next";
import { getCmsPage } from "./server";

/** Metadata of a CMS-managed page: SEO fields from the CMS (ADM-20) or the built-in defaults. */
export async function cmsMetadata(slug: string, title: string, description: string): Promise<Metadata> {
  const cms = await getCmsPage(slug);
  return {
    title: cms?.seo_title ?? cms?.title ?? title,
    description: cms?.seo_description ?? description,
    alternates: { canonical: `/${slug}` },
  };
}
