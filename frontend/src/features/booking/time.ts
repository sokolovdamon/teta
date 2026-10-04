/**
 * Time helpers for sessions. Times come from the API in UTC (ISO 8601) and are always shown in the viewer's
 * timezone with the zone stated (BR-SCHED-01): the client's timezone in CL-03, the psychologist's in PRO-04.
 */

export const MSK = "Europe/Moscow";

/** "МСК" for Moscow, otherwise "UTC+05:00". */
export function zoneLabel(tz: string, at: Date = new Date()): string {
  if (tz === MSK) return "МСК";
  const offset = tzOffsetMinutes(tz, at);
  const sign = offset < 0 ? "−" : "+";
  const abs = Math.abs(offset);
  return `UTC${sign}${String(Math.floor(abs / 60)).padStart(2, "0")}:${String(abs % 60).padStart(2, "0")}`;
}

/** Offset of the timezone from UTC in minutes at the given moment (e.g. 180 for Moscow). */
export function tzOffsetMinutes(tz: string, at: Date): number {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: tz,
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  }).formatToParts(at);
  const get = (type: string) => Number(parts.find((p) => p.type === type)?.value ?? 0);
  const asUtc = Date.UTC(get("year"), get("month") - 1, get("day"), get("hour"), get("minute"), get("second"));
  return Math.round((asUtc - Math.floor(at.getTime() / 1000) * 1000) / 60000);
}

/** "14:00". */
export function timeOf(iso: string, tz: string): string {
  return new Intl.DateTimeFormat("ru-RU", { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
}

/** "12 октября, понедельник". */
export function dayLabel(iso: string, tz: string): string {
  const d = new Date(iso);
  const date = new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "long", timeZone: tz }).format(d);
  const weekday = new Intl.DateTimeFormat("ru-RU", { weekday: "long", timeZone: tz }).format(d);
  return `${date}, ${weekday}`;
}

/** "12 октября, понедельник, 14:00 (МСК)". */
export function sessionDateTime(iso: string, tz: string): string {
  return `${dayLabel(iso, tz)}, ${timeOf(iso, tz)} (${zoneLabel(tz, new Date(iso))})`;
}

/** Calendar day of the moment in the timezone: "2026-10-12". */
export function dayKey(iso: string | Date, tz: string): string {
  const d = typeof iso === "string" ? new Date(iso) : iso;
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(d);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

export function addDaysKey(key: string, days: number): string {
  const [y, m, d] = key.split("-").map(Number);
  const t = new Date(Date.UTC(y, m - 1, d + days));
  return t.toISOString().slice(0, 10);
}

/** ISO weekday of a day key: 1 = Monday … 7 = Sunday. */
export function weekdayOfKey(key: string): number {
  const [y, m, d] = key.split("-").map(Number);
  const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return wd === 0 ? 7 : wd;
}

export function mondayOf(key: string): string {
  return addDaysKey(key, 1 - weekdayOfKey(key));
}

/** UTC instant of a wall-clock time in the timezone ("2026-10-12", "00:00", "Asia/Yekaterinburg"). */
export function zonedToUtc(key: string, time: string, tz: string): Date {
  const [y, m, d] = key.split("-").map(Number);
  const [hh, mm] = time.split(":").map(Number);
  const guess = Date.UTC(y, m - 1, d, hh, mm);
  const first = tzOffsetMinutes(tz, new Date(guess));
  const second = tzOffsetMinutes(tz, new Date(guess - first * 60000));
  return new Date(guess - second * 60000);
}

/** "пн, 12 окт." for calendar column headers. */
export function shortDayLabel(key: string): string {
  const [y, m, d] = key.split("-").map(Number);
  return new Intl.DateTimeFormat("ru-RU", { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" }).format(new Date(Date.UTC(y, m - 1, d)));
}

export type SlotDay = { key: string; label: string; slots: string[] };

/** Free slots grouped by calendar day in the timezone, in chronological order. */
export function groupSlotsByDay(slots: string[], tz: string): SlotDay[] {
  const map = new Map<string, string[]>();
  for (const slot of [...slots].sort()) {
    const key = dayKey(slot, tz);
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(slot);
  }
  return [...map.entries()].map(([key, list]) => ({ key, label: dayLabel(list[0], tz), slots: list }));
}

export type RoomState = "not_yet" | "open" | "closed";

/** The TetaMeet entry is open from P-ROOM-OPEN before the start to P-ROOM-CLOSE after the end. */
export function roomState(room: { opens_at: string; closes_at: string }, now: number = Date.now()): RoomState {
  if (now < new Date(room.opens_at).getTime()) return "not_yet";
  if (now > new Date(room.closes_at).getTime()) return "closed";
  return "open";
}

/** "через 2 ч 5 мин", "через 15 мин", "сейчас". */
export function untilText(iso: string, now: number = Date.now()): string {
  const minutes = Math.round((new Date(iso).getTime() - now) / 60000);
  if (minutes <= 0) return "сейчас";
  if (minutes < 60) return `через ${minutes} мин`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours < 24) return rest ? `через ${hours} ч ${rest} мин` : `через ${hours} ч`;
  const days = Math.round(hours / 24);
  return `через ${days} ${days === 1 ? "день" : days < 5 ? "дня" : "дней"}`;
}
