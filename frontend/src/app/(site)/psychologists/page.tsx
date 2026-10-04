import type { Metadata } from "next";
import Link from "next/link";
import { Alert, EmptyState, LinkButton } from "@/components/ui";
import { CatalogFilters } from "@/features/catalog/CatalogFilters";
import { Pagination } from "@/features/catalog/Pagination";
import { PsychologistCard } from "@/features/catalog/PsychologistCard";
import { activeFilterCount, filtersToSearch, parseFilters } from "@/features/catalog/query";
import { getCatalog, getDictionaries } from "@/features/catalog/server";

export async function generateMetadata({ searchParams }: PageProps<"/psychologists">): Promise<Metadata> {
  const filters = parseFilters(await searchParams);
  const filtered = activeFilterCount(filters) > 0 || filters.q !== "" || filters.sort !== "nearest";
  return {
    title: "Психологи онлайн: каталог специалистов",
    description:
      "Каталог психологов ТЕТА: только дипломированные специалисты с подтверждённой квалификацией. Фильтры по запросам, подходам, цене и ближайшему времени, запись онлайн.",
    alternates: { canonical: filters.page > 1 && !filtered ? `/psychologists?page=${filters.page}` : "/psychologists" },
    robots: filtered ? { index: false, follow: true } : undefined,
  };
}

/** SITE-02: catalog with filters in the URL, SSR. */
export default async function CatalogPage({ searchParams }: PageProps<"/psychologists">) {
  const filters = parseFilters(await searchParams);
  const [catalog, dictionaries] = await Promise.all([getCatalog(filters, 12), getDictionaries()]);
  const total = catalog.status === "ok" ? catalog.data.meta.total : null;

  return (
    <main className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 sm:py-14">
      <header className="grid gap-3">
        <p className="text-sm font-semibold uppercase tracking-wider text-brand">Только дипломированные специалисты</p>
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Психологи ТЕТА</h1>
        <p className="max-w-3xl text-lg text-ink-2">
          Квалификацию каждого психолога проверил администратор, а работа проходит ежемесячную супервизию. Не знаете, кого выбрать?{" "}
          <Link href="/podbor" className="font-medium text-brand hover:underline">
            Пройдите подбор
          </Link>{" "}
          — это займёт несколько минут.
        </p>
      </header>

      <div className="grid gap-8 lg:grid-cols-[320px_1fr] lg:items-start">
        <aside className="lg:sticky lg:top-20">
          <CatalogFilters key={filters.q} filters={filters} dictionaries={dictionaries} total={total} />
        </aside>

        <section aria-label="Список психологов" className="grid gap-8">
          {catalog.status === "error" && (
            <Alert tone="danger" title="Не удалось загрузить каталог">
              Попробуйте обновить страницу через минуту. Если ошибка повторяется, напишите в поддержку.
            </Alert>
          )}
          {catalog.status === "ok" && catalog.data.data.length === 0 && (
            <EmptyState
              title="Под эти условия никого нет"
              description="Попробуйте убрать часть фильтров или выбрать другое время. А ещё можно пройти подбор — мы предложим подходящих специалистов."
              action={
                <div className="flex flex-wrap justify-center gap-2">
                  <LinkButton href="/psychologists" variant="secondary">
                    Сбросить фильтры
                  </LinkButton>
                  <LinkButton href="/podbor">Подобрать психолога</LinkButton>
                </div>
              }
            />
          )}
          {catalog.status === "ok" && catalog.data.data.length > 0 && (
            <>
              <ul className="grid gap-5 md:grid-cols-2">
                {catalog.data.data.map((p) => (
                  <li key={p.id}>
                    <PsychologistCard p={p} />
                  </li>
                ))}
              </ul>
              <Pagination
                page={catalog.data.meta.current_page}
                lastPage={catalog.data.meta.last_page}
                href={(page) => `/psychologists${filtersToSearch({ ...filters, page })}`}
              />
            </>
          )}
        </section>
      </div>
    </main>
  );
}
