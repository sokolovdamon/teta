/**
 * Free slots come from the API in UTC; the visitor sees them in their own timezone (BR-SCHED-01).
 * Calendar days are handled as "YYYY-MM-DD" keys so that week navigation does not depend on the local clock.
 */
export type SlotItem = { iso: string; time: string };
export type DayGroup = { key: string; weekday: string; date: string; slots: SlotItem[] };

const keyFormatters = new Map<string, Intl.DateTimeFormat>();
const timeFormatters = new Map<string, Intl.DateTimeFormat>();

function keyFormatter(tz: string): Intl.DateTimeFormat {
  let f = keyFormatters.get(tz);
  if (!f) {
    f = new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" });
    keyFormatters.set(tz, f);
  }
  return f;
}

function timeFormatter(tz: string): Intl.DateTimeFormat {
  let f = timeFormatters.get(tz);
  if (!f) {
    f = new Intl.DateTimeFormat("ru-RU", { timeZone: tz, hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
    timeFormatters.set(tz, f);
  }
  return f;
}

/** Calendar day of an instant in a timezone: "2026-10-06". */
export function dayKey(iso: string | Date, tz: string): string {
  const parts = keyFormatter(tz).formatToParts(typeof iso === "string" ? new Date(iso) : iso);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

export function slotTime(iso: string, tz: string): string {
  return timeFormatter(tz).format(new Date(iso));
}

export function addDays(key: string, days: number): string {
  const [y, m, d] = key.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

/** ISO weekday of a day key: 1 = Monday … 7 = Sunday. */
export function isoWeekday(key: string): number {
  const [y, m, d] = key.split("-").map(Number);
  const day = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return day === 0 ? 7 : day;
}

export function dayLabels(key: string): { weekday: string; date: string } {
  const [y, m, d] = key.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d, 12));
  return {
    weekday: new Intl.DateTimeFormat("ru-RU", { weekday: "short", timeZone: "UTC" }).format(date),
    date: new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "short", timeZone: "UTC" }).format(date),
  };
}

/** Slots grouped by calendar day in the timezone, days in order, slots in order. */
export function groupSlotsByDay(slots: string[], tz: string): DayGroup[] {
  const map = new Map<string, SlotItem[]>();
  for (const iso of [...slots].sort((a, b) => Date.parse(a) - Date.parse(b))) {
    const key = dayKey(iso, tz);
    const list = map.get(key) ?? [];
    list.push({ iso, time: slotTime(iso, tz) });
    map.set(key, list);
  }
  return [...map.entries()].map(([key, items]) => ({ key, ...dayLabels(key), slots: items }));
}

/** A week (7 consecutive days) starting at `startKey`, including days without slots. */
export function weekView(groups: DayGroup[], startKey: string, days = 7): DayGroup[] {
  const byKey = new Map(groups.map((g) => [g.key, g]));
  return Array.from({ length: days }, (_, i) => {
    const key = addDays(startKey, i);
    return byKey.get(key) ?? { key, ...dayLabels(key), slots: [] };
  });
}

/** Browser timezone, with a safe fallback for very old browsers and SSR. */
export function browserTimezone(fallback = "Europe/Moscow"): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || fallback;
  } catch {
    return fallback;
  }
}

/** "Europe/Moscow" → "Москва (UTC+3)"-like short label of a timezone at a given instant. */
export function timezoneLabel(tz: string, at: Date = new Date()): string {
  try {
    const offset = new Intl.DateTimeFormat("ru-RU", { timeZone: tz, timeZoneName: "shortOffset" })
      .formatToParts(at)
      .find((p) => p.type === "timeZoneName")?.value;
    const city = tz.split("/").pop()?.replace(/_/g, " ") ?? tz;
    return offset ? `${city}, ${offset.replace("GMT", "UTC")}` : city;
  } catch {
    return tz;
  }
}

/** Human label of a nearest slot relative to "now" in the timezone: "сегодня в 15:00", "завтра в 10:00", "чт, 8 окт. в 12:00". */
export function nearestLabel(iso: string, tz: string, now: Date = new Date()): string {
  const key = dayKey(iso, tz);
  const today = dayKey(now, tz);
  const time = slotTime(iso, tz);
  if (key === today) return `сегодня в ${time}`;
  if (key === addDays(today, 1)) return `завтра в ${time}`;
  const { weekday, date } = dayLabels(key);
  return `${weekday}, ${date} в ${time}`;
}
