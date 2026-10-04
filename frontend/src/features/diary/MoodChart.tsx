"use client";

import { clsx } from "clsx";
import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent } from "react";
import { plural } from "@/lib/format";
import { areaPath, bucketLabel, buildSeries, linePath, nearestIndex, segments, summaryText, tickIndexes, xFor, yFor, type Frame } from "./chart";
import { MOODS, formatMood, moodFor } from "./moods";
import type { Dynamics } from "./types";

type Props = { dynamics: Dynamics; compact?: boolean; className?: string };

const TOOLTIP_WIDTH = 168;

/**
 * Mood dynamics as one line on the 1–5 scale (single series: the title names it, no legend). Colors come from
 * design tokens, so the chart follows the dark theme. Crosshair and tooltip on hover and keyboard focus;
 * the same values are available in the table view (DynamicsTable).
 */
export function MoodChart({ dynamics, compact = false, className }: Props) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(compact ? 320 : 640);
  const [active, setActive] = useState<number | null>(null);

  useEffect(() => {
    const el = wrapRef.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(([entry]) => setWidth(Math.max(200, Math.round(entry.contentRect.width))));
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  const height = compact ? 80 : 232;
  const frame: Frame = compact
    ? { width, height, left: 6, right: 6, top: 8, bottom: 8 }
    : { width, height, left: 36, right: 14, top: 14, bottom: 30 };

  const series = useMemo(() => buildSeries(dynamics), [dynamics]);
  const runs = useMemo(() => segments(series), [series]);
  const count = series.length;
  const ticks = compact ? [] : tickIndexes(count, Math.max(2, Math.floor((width - frame.left - frame.right) / 72)));
  const dense = count > 45;

  function pick(clientX: number, target: SVGSVGElement) {
    const rect = target.getBoundingClientRect();
    setActive(nearestIndex(clientX - rect.left, count, frame));
  }

  function onKeyDown(e: KeyboardEvent<SVGSVGElement>) {
    if (!count) return;
    const current = active ?? count - 1;
    const next = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: count - 1 }[e.key];
    if (next === undefined) return;
    e.preventDefault();
    setActive(Math.min(count - 1, Math.max(0, next)));
  }

  function onFocus() {
    let last = count - 1;
    while (last > 0 && series[last].value === null) last--;
    setActive(Math.max(0, last));
  }

  const point = active !== null ? series[active] : null;
  const activeX = active !== null ? xFor(active, count, frame) : 0;
  const tooltipLeft = Math.min(Math.max(activeX - TOOLTIP_WIDTH / 2, 0), Math.max(0, width - TOOLTIP_WIDTH));

  return (
    <div ref={wrapRef} className={clsx("relative w-full", className)}>
      <svg
        width={width}
        height={height}
        role="img"
        aria-label={summaryText(dynamics)}
        tabIndex={0}
        className="block max-w-full touch-pan-y rounded-lg"
        onPointerMove={(e: PointerEvent<SVGSVGElement>) => pick(e.clientX, e.currentTarget)}
        onPointerDown={(e: PointerEvent<SVGSVGElement>) => pick(e.clientX, e.currentTarget)}
        onPointerLeave={() => setActive(null)}
        onKeyDown={onKeyDown}
        onFocus={onFocus}
        onBlur={() => setActive(null)}
      >
        {!compact &&
          MOODS.map((m) => (
            <g key={m.value}>
              <line x1={frame.left} x2={width - frame.right} y1={yFor(m.value, frame)} y2={yFor(m.value, frame)} stroke="var(--line)" strokeWidth={1} />
              <text x={frame.left - 10} y={yFor(m.value, frame) + 5} textAnchor="end" fontSize={15} aria-hidden>
                {m.emoji}
              </text>
            </g>
          ))}
        {compact && <line x1={frame.left} x2={width - frame.right} y1={yFor(1, frame)} y2={yFor(1, frame)} stroke="var(--line)" strokeWidth={1} />}

        {ticks.map((i) => (
          <text
            key={i}
            x={xFor(i, count, frame)}
            y={height - 8}
            textAnchor={i === 0 ? "start" : i === count - 1 ? "end" : "middle"}
            fontSize={12}
            fill="var(--muted)"
          >
            {bucketLabel(series[i].date, dynamics.group)}
          </text>
        ))}

        {active !== null && (
          <line x1={activeX} x2={activeX} y1={frame.top} y2={height - frame.bottom} stroke="var(--line-strong)" strokeWidth={1} />
        )}

        {runs.map((run) => (
          <path key={`a${run[0].index}`} d={areaPath(run, count, frame)} fill="var(--brand)" fillOpacity={0.1} stroke="none" />
        ))}
        {runs.map((run) => (
          <path
            key={`l${run[0].index}`}
            d={linePath(run, count, frame)}
            fill="none"
            stroke="var(--brand)"
            strokeWidth={2}
            strokeLinejoin="round"
            strokeLinecap="round"
          />
        ))}
        {runs.map((run) =>
          run
            .filter((p) => !dense || run.length === 1 || p.index === active)
            .map((p) => (
              <circle
                key={`p${p.index}`}
                cx={xFor(p.index, count, frame)}
                cy={yFor(p.value, frame)}
                r={p.index === active ? 5 : 4}
                fill="var(--brand)"
                stroke="var(--surface)"
                strokeWidth={2}
              />
            )),
        )}
      </svg>

      {point && (
        <div
          className="pointer-events-none absolute top-0 z-10 rounded-xl border border-line bg-surface px-3 py-2 text-sm shadow-sm"
          style={{ left: tooltipLeft, width: TOOLTIP_WIDTH, transform: "translateY(-100%)" }}
          aria-live="polite"
        >
          {point.value !== null ? (
            <>
              <p className="flex items-center gap-1.5">
                <span aria-hidden className="inline-block h-0.5 w-3 rounded-full bg-brand" />
                <strong className="text-base font-semibold text-ink">{formatMood(point.value)}</strong>
                <span className="text-ink-2">
                  {moodFor(point.value)?.emoji} {moodFor(point.value)?.label}
                </span>
              </p>
              <p className="text-muted">
                {bucketLabel(point.date, dynamics.group, true)} · {point.entries} {plural(point.entries, ["отметка", "отметки", "отметок"])}
              </p>
            </>
          ) : (
            <p className="text-muted">{bucketLabel(point.date, dynamics.group, true)}: нет отметок</p>
          )}
        </div>
      )}
    </div>
  );
}

/** The same values as a table — the chart never gates data behind hover. */
export function DynamicsTable({ dynamics }: { dynamics: Dynamics }) {
  if (dynamics.points.length === 0) return null;
  return (
    <details className="mt-3 text-sm">
      <summary className="cursor-pointer text-brand">Показать таблицей</summary>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="text-muted">
              <th className="py-1.5 pr-4 font-medium">{dynamics.group === "week" ? "Неделя" : "День"}</th>
              <th className="py-1.5 pr-4 font-medium">Среднее</th>
              <th className="py-1.5 pr-4 font-medium">Диапазон</th>
              <th className="py-1.5 font-medium">Отметок</th>
            </tr>
          </thead>
          <tbody className="num">
            {dynamics.points.map((p) => (
              <tr key={p.date} className="border-t border-line">
                <td className="py-1.5 pr-4">{bucketLabel(p.date, dynamics.group, true)}</td>
                <td className="py-1.5 pr-4">
                  {formatMood(p.avg_mood)} {moodFor(p.avg_mood)?.emoji}
                </td>
                <td className="py-1.5 pr-4">{p.min_mood === p.max_mood ? p.min_mood : `${p.min_mood}–${p.max_mood}`}</td>
                <td className="py-1.5">{p.entries}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  );
}

/** Most frequent emotion tags: horizontal bars in one hue, values at the tip in text color. */
export function TagBars({ tags }: { tags: Dynamics["tags"] }) {
  if (tags.length === 0) return <p className="text-sm text-muted">Метки эмоций за этот период не отмечены.</p>;
  const max = Math.max(...tags.map((t) => t.count));
  return (
    <ul className="grid gap-2.5">
      {tags.map((t) => (
        <li key={t.id} className="grid grid-cols-[minmax(6.5rem,9rem)_1fr] items-center gap-3 text-sm">
          <span className="truncate text-ink-2">{t.title}</span>
          <span className="flex items-center gap-2">
            <span className="h-3 rounded-r-[4px] bg-brand" style={{ width: `${Math.max(4, (t.count / max) * 100)}%` }} aria-hidden />
            <span className="num shrink-0 text-muted">
              {t.count}
              <span className="sr-only"> {plural(t.count, ["раз", "раза", "раз"])}</span>
            </span>
          </span>
        </li>
      ))}
    </ul>
  );
}
