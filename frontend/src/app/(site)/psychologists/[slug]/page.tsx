import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import type { ReactNode } from "react";
import { Alert, Badge, Card, LinkButton } from "@/components/ui";
import { Avatar } from "@/features/catalog/Avatar";
import { experienceLabel, FORMAT_LABELS } from "@/features/catalog/links";
import { getProfile } from "@/features/catalog/server";
import { SlotPicker } from "@/features/catalog/SlotPicker";
import type { InactiveProfile, PsychologistProfile } from "@/features/catalog/types";
import { ViewTracker } from "@/features/catalog/ViewTracker";
import { PsychologistReviews } from "@/features/reviews/PsychologistReviews";
import { SITE_URL } from "@/lib/config";
import { plural, rub } from "@/lib/format";

const DOC_KINDS: Record<string, string> = {
  diploma: "Диплом",
  retraining: "Профессиональная переподготовка",
  certificate: "Сертификат",
  other: "Документ",
};

export async function generateMetadata({ params }: PageProps<"/psychologists/[slug]">): Promise<Metadata> {
  const { slug } = await params;
  const res = await getProfile(slug);
  if (res.status !== "ok") return { title: "Психолог", robots: { index: false } };
  const p = res.data;
  if (!p.is_active) {
    return { title: `${p.name} — психолог`, description: p.headline ?? undefined, robots: { index: false, follow: true } };
  }
  const topics = p.requests.slice(0, 3).map((r) => r.title.toLowerCase()).join(", ");
  return {
    title: `Психолог ${p.name}${topics ? ` — ${topics}` : ""}`,
    description: p.headline ?? `Психолог ${p.name}: подтверждённая квалификация, запись на онлайн-сессию.`,
    alternates: { canonical: `/psychologists/${p.slug}` },
    openGraph: { title: `Психолог ${p.name}`, description: p.headline ?? undefined, images: p.photo_url ? [p.photo_url] : undefined, type: "profile" },
  };
}

/** SITE-03: active and inactive variants; 404 for profiles that were never approved. */
export default async function PsychologistPage({ params }: PageProps<"/psychologists/[slug]">) {
  const { slug } = await params;
  const res = await getProfile(slug);
  if (res.status === "not_found") notFound();
  if (res.status === "error") {
    return (
      <main className="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <Alert tone="danger" title="Не удалось загрузить страницу психолога">
          Попробуйте обновить страницу через минуту или вернитесь в{" "}
          <Link href="/psychologists" className="font-medium text-brand">
            каталог
          </Link>
          .
        </Alert>
      </main>
    );
  }
  const p = res.data;
  return p.is_active ? <ActiveProfile p={p} /> : <InactiveProfileView p={p} />;
}

function InactiveProfileView({ p }: { p: InactiveProfile }) {
  return (
    <main className="mx-auto grid max-w-3xl gap-6 px-4 py-16 sm:px-6">
      <Breadcrumbs name={p.name} />
      <div className="flex flex-col items-start gap-5 sm:flex-row sm:items-center">
        <Avatar photoUrl={p.photo_url} firstName={p.first_name} lastName={p.last_name} size={112} />
        <div className="grid gap-1">
          <h1 className="text-3xl font-bold tracking-tight">{p.name}</h1>
          {p.headline && <p className="text-ink-2">{p.headline}</p>}
        </div>
      </div>
      <Alert tone="info" title="Психолог временно не принимает новых клиентов">
        Если вы уже записаны, сессии пройдут как обычно. Чтобы начать работу сейчас, выберите другого специалиста — в каталоге только психологи с
        подтверждённой квалификацией.
      </Alert>
      <div className="flex flex-wrap gap-3">
        <LinkButton href="/psychologists">Смотреть каталог</LinkButton>
        <LinkButton href="/podbor" variant="secondary">
          Подобрать психолога
        </LinkButton>
      </div>
    </main>
  );
}

function ActiveProfile({ p }: { p: PsychologistProfile }) {
  const formats = (["individual", "pair"] as const)
    .filter((f) => (f === "individual" ? p.works_individual && p.price_individual : p.works_pair && p.price_pair))
    .map((f) => ({ format: f, price: f === "individual" ? p.price_individual : p.price_pair, duration: p.session_durations[f] }));
  const individualRequests = p.requests.filter((r) => r.format === "individual");
  const pairRequests = p.requests.filter((r) => r.format === "pair");
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Person",
    name: p.name,
    jobTitle: "Психолог",
    description: p.headline ?? undefined,
    image: p.photo_url ?? undefined,
    url: `${SITE_URL}/psychologists/${p.slug}`,
    knowsAbout: p.approaches.map((a) => a.title),
  };

  return (
    <main className="mx-auto grid max-w-6xl gap-10 px-4 py-10 sm:px-6 sm:py-14">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd).replace(/</g, "\\u003c") }} />
      <ViewTracker slug={p.slug} />
      <Breadcrumbs name={p.name} />

      <section className="grid gap-8 lg:grid-cols-[1fr_340px] lg:items-start">
        <div className="flex flex-col gap-6 sm:flex-row">
          <Avatar photoUrl={p.photo_url} firstName={p.first_name} lastName={p.last_name} size={160} className="rounded-3xl" />
          <div className="grid content-start gap-3">
            <div className="flex flex-wrap gap-2">
              <Badge tone="success">Квалификация подтверждена</Badge>
              <Badge tone="neutral">Ежемесячная супервизия</Badge>
            </div>
            <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{p.name}</h1>
            {p.headline && <p className="text-lg text-ink-2">{p.headline}</p>}
            <p className="text-muted">
              {[experienceLabel(p.experience_years), p.age ? `${p.age} ${plural(p.age, ["год", "года", "лет"])}` : null, formats.map((f) => FORMAT_LABELS[f.format].toLowerCase()).join(" и ") + " формат"]
                .filter(Boolean)
                .join(" · ")}
            </p>
          </div>
        </div>

        <Card className="grid gap-4">
          {formats.map((f) => (
            <div key={f.format} className="flex items-baseline justify-between gap-3">
              <span className="text-ink-2">
                {FORMAT_LABELS[f.format]}, {f.duration} мин
              </span>
              <span className="text-xl font-semibold num">{rub(f.price)}</span>
            </div>
          ))}
          {p.price_category && (
            <p className="text-sm text-muted">
              Ценовая категория: <span className="font-medium text-ink-2">{p.price_category.title}</span>
            </p>
          )}
          <LinkButton href="#booking" size="lg">
            Выбрать время
          </LinkButton>
          <p className="text-xs text-muted">
            Оплата спишется с привязанной карты за 12 часов до сессии. До списания отмена бесплатна.{" "}
            <Link href="/prices" className="text-brand hover:underline">
              Правила оплаты
            </Link>
          </p>
        </Card>
      </section>

      <div className="grid gap-10 lg:grid-cols-[1fr_340px]">
        <div className="grid content-start gap-10">
          {p.video_url && (
            <Section title="Видеовизитка">
              <video src={p.video_url} controls preload="metadata" playsInline className="aspect-video w-full rounded-2xl bg-graphite" aria-label={`Видеовизитка: ${p.name}`} />
            </Section>
          )}

          {p.about && (
            <Section title="О себе">
              <div className="grid gap-3 text-ink-2">
                {p.about
                  .split(/\n{2,}/)
                  .map((para) => para.trim())
                  .filter(Boolean)
                  .map((para, i) => (
                    <p key={i} className="whitespace-pre-line leading-relaxed">
                      {para}
                    </p>
                  ))}
              </div>
            </Section>
          )}

          {p.approaches.length > 0 && (
            <Section title="Подходы с пояснениями">
              <ul className="grid gap-4">
                {p.approaches.map((a) => (
                  <li key={a.slug} className="rounded-2xl border border-line bg-surface p-5">
                    <p className="font-semibold">{a.title}</p>
                    {a.description && <p className="mt-1 text-sm text-muted">{a.description}</p>}
                    {a.explanation && (
                      <p className="mt-3 border-l-4 border-brand-tint pl-3 text-ink-2">
                        <span className="sr-only">Как применяет психолог: </span>
                        {a.explanation}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {p.requests.length > 0 && (
            <Section title="С какими запросами работает">
              <RequestLinks title={pairRequests.length ? "Индивидуально" : undefined} items={individualRequests} />
              {pairRequests.length > 0 && <RequestLinks title="С парами" items={pairRequests} />}
            </Section>
          )}

          {p.specializations.length > 0 && (
            <Section title="Специализации">
              <ul className="flex flex-wrap gap-2">
                {p.specializations.map((s) => (
                  <li key={s.slug} className="rounded-full bg-sunken px-3 py-1.5 text-sm text-ink-2">
                    {s.title}
                  </li>
                ))}
              </ul>
            </Section>
          )}

          <section id="booking" className="scroll-mt-24">
            <h2 className="mb-4 text-2xl font-semibold tracking-tight">Записаться на сессию</h2>
            {formats.length > 0 ? (
              <SlotPicker slug={p.slug} formats={formats} />
            ) : (
              <p className="text-muted">Запись временно недоступна.</p>
            )}
          </section>

          <PsychologistReviews psychologistId={p.id} slug={p.slug} />
        </div>

        <aside className="grid content-start gap-6">
          {p.education.length > 0 && (
            <Card>
              <h2 className="mb-3 text-lg font-semibold">Образование</h2>
              <ul className="grid gap-3">
                {p.education.map((e, i) => (
                  <li key={i}>
                    <p className="font-medium">{e.institution}</p>
                    <p className="text-sm text-muted">{[e.specialty, e.year].filter(Boolean).join(", ")}</p>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          {p.documents.length > 0 && (
            <Card>
              <h2 className="mb-1 text-lg font-semibold">Проверенные документы</h2>
              <p className="mb-3 text-sm text-muted">Администратор ТЕТА проверил оригиналы. Сами документы не публикуются.</p>
              <ul className="grid gap-3">
                {p.documents.map((d, i) => (
                  <li key={i} className="flex gap-3">
                    <span aria-hidden className="mt-1 text-success">
                      ✓
                    </span>
                    <span>
                      <span className="block font-medium">{d.title}</span>
                      <span className="block text-sm text-muted">
                        {[DOC_KINDS[d.kind] ?? "Документ", d.institution, d.year].filter(Boolean).join(" · ")}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          <Card className="bg-brand-soft/50">
            <p className="font-medium">Не уверены, что этот специалист вам подходит?</p>
            <p className="mt-1 text-sm text-ink-2">Пройдите подбор: мы покажем главную рекомендацию и все подходящие альтернативы с объяснением.</p>
            <LinkButton href="/podbor" variant="secondary" size="sm" className="mt-3">
              Подобрать психолога
            </LinkButton>
          </Card>
        </aside>
      </div>
    </main>
  );
}

function Breadcrumbs({ name }: { name: string }) {
  return (
    <nav aria-label="Навигация" className="text-sm text-muted">
      <Link href="/" className="hover:text-brand">
        Главная
      </Link>{" "}
      /{" "}
      <Link href="/psychologists" className="hover:text-brand">
        Психологи
      </Link>{" "}
      / <span className="text-ink-2">{name}</span>
    </nav>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="text-2xl font-semibold tracking-tight">{title}</h2>
      {children}
    </section>
  );
}

function RequestLinks({ title, items }: { title?: string; items: PsychologistProfile["requests"] }) {
  if (!items.length) return null;
  return (
    <div className="grid gap-2">
      {title && <p className="text-sm font-medium text-muted">{title}</p>}
      <ul className="flex flex-wrap gap-2">
        {items.map((r) => (
          <li key={r.path}>
            <Link href={r.path} className="inline-block rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink-2 hover:border-brand hover:text-brand">
              {r.title}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
