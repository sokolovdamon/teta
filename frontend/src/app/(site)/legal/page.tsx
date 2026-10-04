import type { Metadata } from "next";
import Link from "next/link";
import { Alert, EmptyState } from "@/components/ui";
import { date } from "@/lib/format";
import { getLegalList } from "@/features/site/server";
import { Container, PageHero } from "@/features/site/ui";

export const metadata: Metadata = {
  title: "Документы",
  description: "Пользовательское соглашение, оферта, политика обработки персональных данных, согласия и другие документы платформы ТЕТА.",
  alternates: { canonical: "/legal" },
};

/** SITE-17: list of public documents with the current version. */
export default async function LegalListPage() {
  const docs = await getLegalList();
  return (
    <Container narrow>
      <PageHero title="Документы" lead="Действующие редакции документов платформы. При изменении документа мы публикуем новую версию с датой вступления в силу." />
      {docs === null && (
        <Alert tone="danger" title="Не удалось загрузить список документов">
          Попробуйте обновить страницу через минуту.
        </Alert>
      )}
      {docs?.length === 0 && <EmptyState title="Документы пока не опубликованы" />}
      {docs && docs.length > 0 && (
        <ul className="divide-y divide-line overflow-hidden rounded-2xl bg-surface ring-1 ring-line">
          {docs.map((d) => (
            <li key={d.slug}>
              <Link href={`/legal/${d.slug}`} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-4 hover:bg-sunken">
                <span className="font-medium">{d.title}</span>
                <span className="text-sm text-muted">
                  Версия {d.version} от {date(d.published_at)}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Container>
  );
}
