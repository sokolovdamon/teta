import type { Metadata } from "next";
import { NotFoundContent } from "@/features/site/NotFoundContent";

export const metadata: Metadata = { title: "Страница не найдена", robots: { index: false } };

/** SITE-18: 404 raised by a public page (notFound()); the site layout provides the header and footer. */
export default function SiteNotFound() {
  return <NotFoundContent />;
}
