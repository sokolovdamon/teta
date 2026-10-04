import Link from "next/link";
import { Badge, LinkButton } from "@/components/ui";
import { plural, rub } from "@/lib/format";
import { Avatar } from "./Avatar";
import { experienceLabel, profileHref } from "./links";
import { NearestSlot } from "./NearestSlot";
import type { PsychologistCard as Card } from "./types";

/** SITE-02 card: photo or initials, name, headline, approaches, experience, price and category, nearest slot. No ratings (DEC-32). */
export function PsychologistCard({ p, compact = false }: { p: Card; compact?: boolean }) {
  const format = p.nearest_slot_format;
  const price = format === "pair" ? p.price_pair : p.price_individual;
  const approaches = p.approaches.slice(0, compact ? 2 : 3);
  const more = p.approaches.length - approaches.length;
  const experience = experienceLabel(p.experience_years);

  return (
    <article className="flex h-full flex-col gap-4 rounded-2xl border border-line bg-surface p-5 transition-colors hover:border-brand-tint sm:p-6">
      <div className="flex gap-4">
        <Link href={profileHref(p.slug)} className="shrink-0" tabIndex={-1} aria-hidden>
          <Avatar photoUrl={p.photo_url} firstName={p.first_name} lastName={p.last_name} size={compact ? 72 : 88} />
        </Link>
        <div className="grid min-w-0 content-start gap-1">
          <h3 className="text-lg font-semibold leading-snug">
            <Link href={profileHref(p.slug)} className="hover:text-brand">
              {p.name}
            </Link>
          </h3>
          <p className="text-sm text-muted">
            {[experience, p.age ? `${p.age} ${plural(p.age, ["год", "года", "лет"])}` : null].filter(Boolean).join(" · ")}
          </p>
          <div className="mt-1 flex flex-wrap gap-1.5">
            <Badge tone="success">Квалификация подтверждена</Badge>
            {p.has_video && <Badge tone="neutral">Видеовизитка</Badge>}
          </div>
        </div>
      </div>

      {p.headline && <p className={compact ? "line-clamp-2 text-ink-2" : "line-clamp-3 text-ink-2"}>{p.headline}</p>}

      {approaches.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" aria-label="Подходы">
          {approaches.map((a) => (
            <li key={a.slug} className="rounded-full bg-sunken px-2.5 py-1 text-xs text-ink-2">
              {a.title}
            </li>
          ))}
          {more > 0 && <li className="rounded-full px-1.5 py-1 text-xs text-muted">ещё {more}</li>}
        </ul>
      )}

      <div className="mt-auto grid gap-3 border-t border-line pt-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <p>
            <span className="text-lg font-semibold num">{rub(price)}</span>{" "}
            <span className="text-sm text-muted">{format === "pair" ? "парная сессия" : "за сессию"}</span>
          </p>
          {p.price_category && <Badge tone="brand">{p.price_category.title}</Badge>}
        </div>
        <NearestSlot slug={p.slug} iso={p.nearest_slot} format={format} />
        <div className="flex flex-wrap gap-2">
          <LinkButton href={`${profileHref(p.slug)}#booking`} size="sm" className="flex-1 sm:flex-none">
            Записаться
          </LinkButton>
          {!compact && (
            <LinkButton href={profileHref(p.slug)} size="sm" variant="secondary" className="flex-1 sm:flex-none">
              Подробнее
            </LinkButton>
          )}
        </div>
      </div>
    </article>
  );
}
