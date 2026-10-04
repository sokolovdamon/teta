import type { Metadata } from "next";
import Link from "next/link";
import { Logo } from "@/components/layout/Logo";
import { SiteFooter } from "@/components/layout/SiteFooter";
import { NotFoundContent } from "@/features/site/NotFoundContent";

export const metadata: Metadata = { title: "Страница не найдена", robots: { index: false } };

/** SITE-18: 404 for unknown URLs (rendered in the root layout, so it brings a light header and the footer). */
export default function NotFound() {
  return (
    <>
      <header className="border-b border-line bg-ground">
        <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
          <Logo />
          <Link href="/help-now" className="text-sm font-medium text-danger">
            Экстренная помощь
          </Link>
        </div>
      </header>
      <NotFoundContent />
      <SiteFooter />
    </>
  );
}
