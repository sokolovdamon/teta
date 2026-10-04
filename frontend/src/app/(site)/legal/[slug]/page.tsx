import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Alert, Markdown } from "@/components/ui";
import { date } from "@/lib/format";
import { getLegalDocument } from "@/features/site/server";
import { Container } from "@/features/site/ui";

export async function generateMetadata({ params }: PageProps<"/legal/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const doc = await getLegalDocument(slug);
  if (!doc || doc === "error") return { title: "Документ" };
  return { title: doc.title, description: `${doc.title}, версия ${doc.version}.`, alternates: { canonical: `/legal/${doc.slug}` } };
}

/** SITE-17: one document, markdown of the current version (CONSENT). */
export default async function LegalDocumentPage({ params }: PageProps<"/legal/[slug]">) {
  const { slug } = await params;
  const doc = await getLegalDocument(slug);
  if (doc === null) notFound();
  if (doc === "error") {
    return (
      <Container narrow>
        <Alert tone="danger" title="Не удалось загрузить документ">
          Попробуйте обновить страницу через минуту.
        </Alert>
      </Container>
    );
  }
  return (
    <Container narrow className="gap-6">
      <nav aria-label="Навигация" className="text-sm text-muted">
        <Link href="/legal" className="hover:text-brand">
          Документы
        </Link>{" "}
        / <span className="text-ink-2">{doc.title}</span>
      </nav>
      <header className="grid gap-2">
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{doc.title}</h1>
        <p className="text-sm text-muted">
          Версия {doc.version}, действует с {date(doc.published_at)}
        </p>
      </header>
      <article className="rounded-3xl bg-surface p-6 ring-1 ring-line sm:p-10">
        <Markdown>{stripLeadingTitle(doc.body, doc.title)}</Markdown>
      </article>
    </Container>
  );
}

/** The document body often repeats the title as "# Title" — the page already has an H1. */
function stripLeadingTitle(body: string, title: string): string {
  const lines = body.split("\n");
  if (lines[0]?.replace(/^#\s+/, "").trim() === title.trim()) return lines.slice(1).join("\n");
  return body;
}
