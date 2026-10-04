import { LinkButton } from "@/components/ui";

/** SITE-01 placeholder; the full landing is built in stream A. */
export default function HomePage() {
  return (
    <main className="mx-auto max-w-7xl px-4 py-16 sm:px-6">
      <p className="text-sm font-semibold uppercase tracking-wider text-brand">Портал психологической помощи</p>
      <h1 className="mt-3 max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">Только дипломированные специалисты</h1>
      <p className="mt-5 max-w-2xl text-lg text-ink-2">
        Подберём психолога по вашему запросу. Дипломы каждого специалиста проверены, а работа проходит ежемесячную супервизию.
      </p>
      <div className="mt-8 flex flex-wrap gap-3">
        <LinkButton href="/podbor" size="lg">
          Подобрать психолога
        </LinkButton>
        <LinkButton href="/psychologists" variant="secondary" size="lg">
          Смотреть каталог
        </LinkButton>
      </div>
    </main>
  );
}
