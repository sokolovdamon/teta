"use client";

import { clsx } from "clsx";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { Alert, Button, Chip, LinkButton } from "@/components/ui";
import { api } from "@/lib/api";
import { rub } from "@/lib/format";
import { bookingHref, FORMAT_LABELS } from "./links";
import { addDays, dayKey, groupSlotsByDay, timezoneLabel, weekView } from "./slots";
import type { SessionFormat, SlotsResponse } from "./types";
import { useBrowserTimezone } from "./useTimezone";

type Props = {
  slug: string;
  formats: { format: SessionFormat; price: number | null; duration: number }[];
};

type State = { status: "loading" } | { status: "error" } | { status: "ok"; data: SlotsResponse };

const RANGE_DAYS = 35;

/**
 * SITE-03 slot picker: a week of free slots in the visitor's timezone (Intl), switching between individual and
 * pair sessions. A slot leads to the booking wizard with the UTC start time.
 */
export function SlotPicker({ slug, formats }: Props) {
  const tz = useBrowserTimezone();
  const [format, setFormat] = useState<SessionFormat>(formats[0]?.format ?? "individual");
  const [byFormat, setByFormat] = useState<Partial<Record<SessionFormat, State>>>({});
  const [weekStart, setWeekStart] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const state: State = byFormat[format] ?? { status: "loading" };

  useEffect(() => {
    if (byFormat[format]?.status === "ok") return;
    const controller = new AbortController();
    const from = new Date();
    const to = new Date(from.getTime() + RANGE_DAYS * 86400_000);
    api<{ data: SlotsResponse }>(`/psychologists/${encodeURIComponent(slug)}/slots`, {
      query: { format, from: from.toISOString(), to: to.toISOString() },
      signal: controller.signal,
    })
      .then((r) => setByFormat((s) => ({ ...s, [format]: { status: "ok", data: r.data } })))
      .catch((e: unknown) => {
        if ((e as { name?: string })?.name !== "AbortError") setByFormat((s) => ({ ...s, [format]: { status: "error" } }));
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reload only on format or retry
  }, [slug, format, attempt]);

  const slots = state.status === "ok" ? state.data.slots : null;
  const groups = useMemo(() => (slots ? groupSlotsByDay(slots, tz) : []), [slots, tz]);
  const today = dayKey(new Date(), tz);
  const lastKey = groups.length ? groups[groups.length - 1].key : today;
  const firstWithSlots = groups[0]?.key ?? today;
  const start = weekStart ?? (firstWithSlots > addDays(today, 6) ? firstWithSlots : today);
  const week = weekView(groups, start);
  const current = formats.find((f) => f.format === format);

  return (
    <div className="grid gap-5">
      {formats.length > 1 && (
        <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Формат сессии">
          {formats.map((f) => (
            <Chip
              key={f.format}
              active={f.format === format}
              onClick={() => {
                setFormat(f.format);
                setWeekStart(null);
              }}
            >
              {FORMAT_LABELS[f.format]} · {f.duration} мин · {rub(f.price)}
            </Chip>
          ))}
        </div>
      )}
      {formats.length === 1 && current && (
        <p className="text-ink-2">
          {FORMAT_LABELS[current.format]} сессия · {current.duration} мин · <span className="font-semibold num">{rub(current.price)}</span>
        </p>
      )}

      <p className="text-sm text-muted" suppressHydrationWarning>
        Время показано в вашем часовом поясе: {timezoneLabel(tz)}
      </p>

      {state.status === "loading" && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" aria-busy>
          {Array.from({ length: 7 }, (_, i) => (
            <div key={i} className="h-28 animate-pulse rounded-xl bg-sunken" />
          ))}
        </div>
      )}

      {state.status === "error" && (
        <Alert tone="danger" title="Не удалось загрузить свободное время">
          <Button variant="ghost" size="sm" className="mt-2" onClick={() => {
            setByFormat((s) => ({ ...s, [format]: undefined }));
            setAttempt((a) => a + 1);
          }}>
            Попробовать ещё раз
          </Button>
        </Alert>
      )}

      {state.status === "ok" && groups.length === 0 && (
        <div className="grid gap-3 rounded-2xl border border-dashed border-line-strong px-5 py-8 text-center">
          <p className="font-medium">Свободного времени в ближайшие недели нет</p>
          <p className="text-muted">Посмотрите других специалистов или пройдите подбор — мы предложим тех, у кого есть время.</p>
          <div className="flex flex-wrap justify-center gap-2">
            <LinkButton href="/psychologists?within=7" variant="secondary">
              Психологи со свободным временем
            </LinkButton>
            <LinkButton href="/podbor">Подобрать психолога</LinkButton>
          </div>
        </div>
      )}

      {state.status === "ok" && groups.length > 0 && (
        <>
          <div className="flex items-center justify-between gap-3">
            <Button variant="secondary" size="sm" disabled={start <= today} onClick={() => setWeekStart(addDays(start, -7) < today ? today : addDays(start, -7))}>
              ← Раньше
            </Button>
            <p className="text-sm font-medium text-ink-2">
              {week[0].date} — {week[6].date}
            </p>
            <Button variant="secondary" size="sm" disabled={addDays(start, 7) > lastKey} onClick={() => setWeekStart(addDays(start, 7))}>
              Позже →
            </Button>
          </div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            {week.map((day) => (
              <div key={day.key} className={clsx("grid content-start gap-2 rounded-xl p-2", day.slots.length ? "bg-ground" : "bg-transparent")}>
                <p className="text-center text-sm">
                  <span className="font-semibold capitalize">{day.weekday}</span> <span className="text-muted">{day.date}</span>
                </p>
                {day.slots.length === 0 ? (
                  <p className="py-2 text-center text-xs text-muted">нет времени</p>
                ) : (
                  day.slots.map((s) => (
                    <Link
                      key={s.iso}
                      href={bookingHref(slug, format, s.iso)}
                      className="rounded-lg border border-line bg-surface py-2 text-center text-[15px] font-medium num hover:border-brand hover:text-brand"
                    >
                      {s.time}
                    </Link>
                  ))
                )}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
