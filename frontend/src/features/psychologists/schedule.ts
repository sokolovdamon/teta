import type { Dictionaries } from "@/features/catalog/types";
import type { ScheduleInterval } from "./types";

/** "09:30" → 570; "24:00" → 1440; invalid → null. */
export function toMinutes(hhmm: string): number | null {
  const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm.trim());
  if (!m) return null;
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (min > 59 || h > 24 || (h === 24 && min > 0)) return null;
  return h * 60 + min;
}

/**
 * Client-side check of the weekly editor before PUT /pro/schedule/intervals (the server validates the same rules):
 * time format, end after start, an interval fits at least one session, no overlaps within a day.
 * Returns errors by interval index.
 */
export function validateIntervals(intervals: ScheduleInterval[], minLength: number): Record<number, string> {
  const errors: Record<number, string> = {};
  intervals.forEach((i, idx) => {
    const from = toMinutes(i.starts_at);
    const to = toMinutes(i.ends_at);
    if (from === null || to === null || from >= 24 * 60) errors[idx] = "Время в формате ЧЧ:ММ";
    else if (to <= from) errors[idx] = "Конец должен быть позже начала";
    else if (to - from < minLength) errors[idx] = `Интервал короче сессии (${minLength} мин)`;
  });
  const byDay = new Map<number, number[]>();
  intervals.forEach((i, idx) => {
    if (errors[idx]) return;
    byDay.set(i.weekday, [...(byDay.get(i.weekday) ?? []), idx]);
  });
  for (const list of byDay.values()) {
    const sorted = [...list].sort((a, b) => (toMinutes(intervals[a].starts_at) ?? 0) - (toMinutes(intervals[b].starts_at) ?? 0));
    for (let k = 1; k < sorted.length; k++) {
      const prevEnd = toMinutes(intervals[sorted[k - 1]].ends_at) ?? 0;
      const start = toMinutes(intervals[sorted[k]].starts_at) ?? 0;
      if (start < prevEnd) errors[sorted[k]] = "Пересекается с другим интервалом";
    }
  }
  return errors;
}

/** Minutes east of UTC of a timezone at an instant (Europe/Moscow → 180). */
export function tzOffsetMinutes(tz: string, at: Date): number {
  const name = new Intl.DateTimeFormat("en-US", { timeZone: tz, timeZoneName: "longOffset" }).formatToParts(at).find((p) => p.type === "timeZoneName")?.value ?? "GMT";
  const m = /GMT([+-])(\d{2}):?(\d{2})?/.exec(name);
  if (!m) return 0;
  const sign = m[1] === "-" ? -1 : 1;
  return sign * (Number(m[2]) * 60 + Number(m[3] ?? 0));
}

/** Local wall time in a timezone → UTC ISO: ("2026-10-10", "00:00", "Asia/Vladivostok") → "2026-10-09T14:00:00.000Z". */
export function zonedToIso(dateKey: string, time: string, tz: string): string {
  const [y, mo, d] = dateKey.split("-").map(Number);
  const [h, mi] = time.split(":").map(Number);
  const guess = Date.UTC(y, mo - 1, d, h, mi);
  let ts = guess - tzOffsetMinutes(tz, new Date(guess)) * 60_000;
  const corrected = guess - tzOffsetMinutes(tz, new Date(ts)) * 60_000;
  if (corrected !== ts) ts = corrected;
  return new Date(ts).toISOString();
}

/** DEC-55: category by the price of an individual session (kopecks). */
export function categoryFor(price: number | null, categories: Dictionaries["price_categories"]) {
  if (price === null || Number.isNaN(price)) return null;
  return categories.find((c) => price >= c.min_price && (c.max_price === null || price <= c.max_price)) ?? null;
}

/** "3 500" / "3500,50" → 350050 kopecks; empty → null. */
export function rublesToKopecks(value: string): number | null {
  const clean = value.replace(/\s/g, "").replace(",", ".");
  if (clean === "") return null;
  const n = Number(clean);
  return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) : null;
}

export function kopecksToRubles(value: number | null | undefined): string {
  return value === null || value === undefined ? "" : String(value / 100);
}
