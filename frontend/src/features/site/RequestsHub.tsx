import Link from "next/link";
import { Alert, LinkButton } from "@/components/ui";
import type { Dictionaries } from "@/features/catalog/types";
import { Container, CtaBand, PageHero, TextLink } from "./ui";

type Group = Dictionaries["request_groups"][number];

/** SITE-06 hubs: /help — all requests by group, /help/para — requests for couples. */
export function RequestsHub({ groups, pairOnly = false }: { groups: Group[] | null; pairOnly?: boolean }) {
  const visible = (groups ?? []).filter((g) => (pairOnly ? g.format === "pair" : true));
  return (
    <Container>
      <PageHero
        eyebrow={pairOnly ? "Для пары" : "С чем помогаем"}
        title={pairOnly ? "Психолог для пары" : "С чем помогают психологи ТЕТА"}
        lead={
          pairOnly
            ? "Парная сессия длится 90 минут: психолог работает с обоими партнёрами сразу. Выберите, что сейчас важнее всего."
            : "Выберите запрос, который откликается, — расскажем, как проходит работа, и покажем психологов, которые с ним работают."
        }
        actions={
          <LinkButton href={pairOnly ? "/podbor?format=pair" : "/podbor"} size="lg">
            Подобрать психолога
          </LinkButton>
        }
      />

      {groups === null && (
        <Alert tone="danger" title="Не удалось загрузить список запросов">
          Попробуйте обновить страницу через минуту.
        </Alert>
      )}

      <div className="grid gap-10">
        {visible.map((g) => (
          <section key={g.slug} className="grid gap-4" aria-labelledby={`group-${g.slug}`}>
            <h2 id={`group-${g.slug}`} className="text-2xl font-semibold tracking-tight">
              {g.title}
            </h2>
            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {g.requests.map((r) => (
                <li key={r.id}>
                  <Link
                    href={r.path}
                    className="flex h-full items-center justify-between gap-3 rounded-2xl border border-line bg-surface px-5 py-4 transition-colors hover:border-brand hover:bg-brand-soft/40"
                  >
                    <span className="font-medium">
                      {r.title}
                      {r.age_label && <span className="ml-2 rounded-full bg-sunken px-2 py-0.5 text-xs text-muted">{r.age_label}</span>}
                    </span>
                    <span aria-hidden className="text-brand">
                      →
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      {!pairOnly && (
        <p className="text-ink-2">
          Работаете над отношениями вместе? Посмотрите <TextLink href="/help/para">запросы для пар</TextLink>.
        </p>
      )}

      <CtaBand
        title="Не нашли свой запрос?"
        text="Это нормально — часто трудно назвать, что происходит. Пройдите подбор или поговорите с ботом Германом: он поможет сформулировать."
        actions={
          <LinkButton href="/podbor" variant="secondary" size="lg">
            Подобрать психолога
          </LinkButton>
        }
      />
    </Container>
  );
}
