import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Alert, LinkButton, Markdown } from "@/components/ui";
import { PsychologistCard } from "@/features/catalog/PsychologistCard";
import { EMPTY_FILTERS } from "@/features/catalog/query";
import { getCatalog, getDictionaries, getRequestLanding } from "@/features/catalog/server";
import type { Dictionaries, SessionFormat } from "@/features/catalog/types";
import { Container, CtaBand, PageHero, Section, TextLink } from "./ui";

export async function requestLandingMetadata(slug: string, format: SessionFormat): Promise<Metadata> {
  const res = await getRequestLanding(slug, format);
  if (res.status !== "ok") return { title: "Запрос", robots: { index: false } };
  const r = res.data;
  return {
    title: { absolute: r.seo_title ?? `${r.title} — помощь психолога онлайн · ТЕТА` },
    description: r.seo_description ?? `Психологи ТЕТА работают с запросом «${r.title}». Только дипломированные специалисты, подбор и запись онлайн.`,
    alternates: { canonical: r.path },
  };
}

/** SITE-06: landing of one of the 43 requests (DEC-11): text from ADM-13 or a careful default, psychologists, CTA. */
export async function RequestLandingPage({ slug, format }: { slug: string; format: SessionFormat }) {
  const [res, dictionaries] = await Promise.all([getRequestLanding(slug, format), getDictionaries()]);
  if (res.status === "not_found") notFound();
  if (res.status === "error") {
    return (
      <Container narrow>
        <Alert tone="danger" title="Не удалось загрузить страницу">
          Попробуйте обновить страницу через минуту или откройте <TextLink href="/help">все запросы</TextLink>.
        </Alert>
      </Container>
    );
  }
  const r = res.data;
  const catalog = await getCatalog({ ...EMPTY_FILTERS, requests: [r.slug], format }, 6);
  const psychologists = catalog.status === "ok" ? catalog.data.data : [];
  const total = catalog.status === "ok" ? catalog.data.meta.total : 0;
  const related = relatedRequests(dictionaries, r.group.slug, r.slug);
  const isPair = format === "pair";
  const catalogHref = `/psychologists?requests=${r.slug}&format=${format}`;
  const display = displayTitle(r.title, r.group.slug);

  return (
    <Container>
      <nav aria-label="Навигация" className="-mb-8 text-sm text-muted">
        <Link href="/help" className="hover:text-brand">
          С чем помогаем
        </Link>{" "}
        {isPair && (
          <>
            /{" "}
            <Link href="/help/para" className="hover:text-brand">
              Для пары
            </Link>{" "}
          </>
        )}
        / <span className="text-ink-2">{r.title}</span>
      </nav>

      <PageHero
        eyebrow={`${isPair ? "Психолог для пары" : "Психолог онлайн"} · ${r.group.title}${r.age_label ? ` · ${r.age_label}` : ""}`}
        title={display}
        lead={
          r.landing_lead ??
          (isPair
            ? `Запрос «${lower(display)}» — повод прийти к психологу вдвоём. Специалист помогает обоим партнёрам услышать друг друга и найти решение, которое подходит вам двоим.`
            : `С запросом «${lower(display)}» часто приходят к психологу. Вы не обязаны справляться в одиночку: специалист поможет разобраться в происходящем и найти опору.`)
        }
        actions={
          <>
            <LinkButton href={`/podbor?request=${encodeURIComponent(r.slug)}&format=${format}`} size="lg">
              Подобрать психолога
            </LinkButton>
            <LinkButton href={catalogHref} size="lg" variant="secondary">
              Психологи по запросу{total ? ` (${total})` : ""}
            </LinkButton>
          </>
        }
      />

      {r.landing_body ? (
        <article className="rounded-3xl bg-surface p-6 ring-1 ring-line sm:p-10">
          <Markdown>{r.landing_body}</Markdown>
        </article>
      ) : (
        <Section title="Как проходит работа с психологом">
          <ul className="grid gap-4 md:grid-cols-3">
            {(isPair ? PAIR_STEPS : INDIVIDUAL_STEPS).map((s) => (
              <li key={s.title} className="grid content-start gap-2 rounded-2xl bg-surface p-5 ring-1 ring-line">
                <p className="font-semibold">{s.title}</p>
                <p className="text-[15px] text-ink-2">{s.text}</p>
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Section
        title={isPair ? "Психологи, которые работают с парами по этому запросу" : "Психологи, которые работают с этим запросом"}
        lead="Квалификация каждого специалиста подтверждена, а работа проходит ежемесячную супервизию."
      >
        {psychologists.length > 0 ? (
          <>
            <ul className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
              {psychologists.map((p) => (
                <li key={p.id}>
                  <PsychologistCard p={p} compact />
                </li>
              ))}
            </ul>
            {total > psychologists.length && (
              <div>
                <LinkButton href={catalogHref} variant="secondary">
                  Все психологи по запросу ({total})
                </LinkButton>
              </div>
            )}
          </>
        ) : (
          <p className="text-ink-2">
            Сейчас нет свободных специалистов именно с этим запросом. <TextLink href="/podbor">Пройдите подбор</TextLink> — мы предложим ближайшие по
            опыту.
          </p>
        )}
      </Section>

      {related.length > 0 && (
        <Section title="Похожие запросы">
          <ul className="flex flex-wrap gap-2">
            {related.map((x) => (
              <li key={x.path}>
                <Link href={x.path} className="inline-block rounded-full border border-line bg-surface px-3.5 py-1.5 text-sm text-ink-2 hover:border-brand hover:text-brand">
                  {x.title}
                </Link>
              </li>
            ))}
          </ul>
        </Section>
      )}

      <Alert tone="warning" title="Если сейчас очень тяжело">
        Если есть угроза жизни — вашей или чужой, звоните 112. Куда ещё можно обратиться прямо сейчас — на странице{" "}
        <TextLink href="/help-now">«Экстренная помощь»</TextLink>.
      </Alert>

      <CtaBand
        title="Сделайте первый шаг"
        text="Ответьте на несколько вопросов — и мы предложим психологов, которые работают с вашим запросом."
        actions={
          <LinkButton href={`/podbor?request=${encodeURIComponent(r.slug)}&format=${format}`} variant="secondary" size="lg">
            Подобрать психолога
          </LinkButton>
        }
      />
    </Container>
  );
}

const INDIVIDUAL_STEPS = [
  { title: "Разобраться", text: "На первых встречах психолог помогает понять, что происходит, как это проявляется и что поддерживает трудность." },
  { title: "Найти опору", text: "Вместе вы ищете ресурсы и способы справляться — с учётом вашего опыта и подхода психолога." },
  { title: "Закрепить", text: "Между сессиями — рекомендации и упражнения, а дневник эмоций помогает замечать изменения." },
];

const PAIR_STEPS = [
  { title: "Услышать друг друга", text: "Психолог создаёт безопасное пространство, где каждый партнёр может говорить и быть услышанным." },
  { title: "Понять, что происходит", text: "Вы вместе разбираетесь в повторяющихся сценариях и в том, что стоит за конфликтами." },
  { title: "Договориться", text: "Ищете решения, которые подходят вам обоим. Парная сессия длится 90 минут." },
];

/** "С партнёром" in the group "Отношения" reads as "Отношения: с партнёром". */
function displayTitle(title: string, groupSlug: string): string {
  return groupSlug === "otnosheniya" ? `Отношения: ${lower(title)}` : title;
}

function lower(title: string): string {
  if (/^[А-ЯЁ]{2}/.test(title)) return title; // abbreviations like «СДВГ»
  return title.charAt(0).toLowerCase() + title.slice(1);
}

function relatedRequests(d: Dictionaries | null, groupSlug: string, slug: string) {
  const group = d?.request_groups.find((g) => g.slug === groupSlug);
  return (group?.requests ?? []).filter((r) => r.slug !== slug).slice(0, 12);
}
