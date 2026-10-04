import { plural } from "@/lib/format";
import { formatMood, moodFor } from "./moods";
import type { Dynamics, DynamicsGroup } from "./types";

/**
 * Data shaping for the mood chart (CL-06, PRO-06). Dates are calendar days "YYYY-MM-DD" in the client's
 * time zone, computed by the backend; here they are handled as UTC days so the browser zone never shifts them.
 */

const DAY = 86_400_000;

export type SeriesPoint = { date: string; value: number | null; min: number | null; max: number | null; entries: number };

export type Frame = { width: number; height: number; left: number; right: number; top: number; bottom: number };

export function parseDay(iso: string): number {
  const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
  return Date.UTC(y, m - 1, d);
}

export function formatDay(ts: number): string {
  return new Date(ts).toISOString().slice(0, 10);
}

/** Monday of the week, as PostgreSQL date_trunc('week') returns it. */
export function weekStart(iso: string): string {
  const ts = parseDay(iso);
  const dayOfWeek = (new Date(ts).getUTCDay() + 6) % 7;
  return formatDay(ts - dayOfWeek * DAY);
}

/** Every bucket of the period: each day, or each week start. */
export function buckets(from: string, to: string, group: DynamicsGroup): string[] {
  const step = group === "week" ? 7 * DAY : DAY;
  const start = parseDay(group === "week" ? weekStart(from) : from);
  const end = parseDay(to);
  const out: string[] = [];
  for (let t = start; t <= end; t += step) out.push(formatDay(t));
  return out;
}

/** One point per bucket; buckets without entries are null (the line breaks there, nothing is invented). */
export function buildSeries(d: Pick<Dynamics, "from" | "to" | "group" | "points">): SeriesPoint[] {
  const byDate = new Map(d.points.map((p) => [p.date, p]));
  return buckets(d.from, d.to, d.group).map((date) => {
    const p = byDate.get(date);
    return p
      ? { date, value: p.avg_mood, min: p.min_mood, max: p.max_mood, entries: p.entries }
      : { date, value: null, min: null, max: null, entries: 0 };
  });
}

/** Runs of consecutive buckets with data — each run is drawn as one line. */
export function segments(series: SeriesPoint[]): { index: number; value: number }[][] {
  const runs: { index: number; value: number }[][] = [];
  let current: { index: number; value: number }[] = [];
  series.forEach((p, index) => {
    if (p.value === null) {
      if (current.length) runs.push(current);
      current = [];
    } else {
      current.push({ index, value: p.value });
    }
  });
  if (current.length) runs.push(current);
  return runs;
}

export function xFor(index: number, count: number, f: Frame): number {
  const inner = f.width - f.left - f.right;
  return count <= 1 ? f.left + inner / 2 : f.left + (inner * index) / (count - 1);
}

/** Mood 5 at the top, mood 1 at the bottom of the plot. */
export function yFor(value: number, f: Frame): number {
  const inner = f.height - f.top - f.bottom;
  const clamped = Math.min(5, Math.max(1, value));
  return f.top + (inner * (5 - clamped)) / 4;
}

export function linePath(run: { index: number; value: number }[], count: number, f: Frame): string {
  return run.map((p, i) => `${i === 0 ? "M" : "L"}${round(xFor(p.index, count, f))},${round(yFor(p.value, f))}`).join(" ");
}

/** A wash under the line down to the bottom of the plot. Empty for a single point. */
export function areaPath(run: { index: number; value: number }[], count: number, f: Frame): string {
  if (run.length < 2) return "";
  const base = round(yFor(1, f));
  const first = round(xFor(run[0].index, count, f));
  const last = round(xFor(run[run.length - 1].index, count, f));
  return `${linePath(run, count, f)} L${last},${base} L${first},${base} Z`;
}

/** The bucket under the pointer (the crosshair snaps to it). */
export function nearestIndex(x: number, count: number, f: Frame): number {
  if (count <= 1) return 0;
  const inner = f.width - f.left - f.right;
  const i = Math.round(((x - f.left) / inner) * (count - 1));
  return Math.min(count - 1, Math.max(0, i));
}

/** Evenly spaced label positions, always including the first and the last bucket. */
export function tickIndexes(count: number, max: number): number[] {
  if (count <= 0) return [];
  if (count <= max) return Array.from({ length: count }, (_, i) => i);
  const step = (count - 1) / (max - 1);
  const out = new Set<number>();
  for (let i = 0; i < max; i++) out.add(Math.round(i * step));
  return [...out];
}

const shortDate = new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "short", timeZone: "UTC" });
const longDate = new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "long", timeZone: "UTC" });

export function bucketLabel(date: string, group: DynamicsGroup, long = false): string {
  const fmt = long ? longDate : shortDate;
  const start = fmt.format(new Date(parseDay(date)));
  if (group === "day") return start;
  return `${start} – ${fmt.format(new Date(parseDay(date) + 6 * DAY))}`;
}

/** Text alternative of the chart for screen readers. */
export function summaryText(d: Dynamics): string {
  const period = `с ${bucketLabel(d.from, "day", true)} по ${bucketLabel(d.to, "day", true)}`;
  if (d.summary.entries === 0) return `Настроение ${period}: отметок нет.`;
  const mood = moodFor(d.summary.avg_mood);
  const entries = `${d.summary.entries} ${plural(d.summary.entries, ["отметка", "отметки", "отметок"])}`;
  return `Настроение ${period}: ${entries}, в среднем ${formatMood(d.summary.avg_mood)} из 5${mood ? ` («${mood.label}»)` : ""}.`;
}

function round(n: number): number {
  return Math.round(n * 10) / 10;
}
