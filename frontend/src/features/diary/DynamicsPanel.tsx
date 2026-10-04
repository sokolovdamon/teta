"use client";

import { clsx } from "clsx";
import { useEffect, useState, type ReactNode } from "react";
import { Alert, Button, Card, Chip, EmptyState } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { DynamicsTable, MoodChart, TagBars } from "./MoodChart";
import { PERIODS, formatMood, moodFor } from "./moods";
import type { Dynamics, PeriodPreset } from "./types";

type Props = {
  load: (period: PeriodPreset) => Promise<Dynamics>;
  /** Change it to reload, e.g. after a new entry. */
  reloadKey?: number;
  emptyTitle: string;
  emptyDescription?: ReactNode;
  forbiddenText?: ReactNode;
  /** Shown above the chart for the given data (e.g. the DM-08 access window). */
  note?: (d: Dynamics) => ReactNode;
  initialPeriod?: PeriodPreset;
};

/** CL-06 and PRO-06: period presets in one row above, summary tiles, mood line, table view and frequent tags. */
export function DynamicsPanel({ load, reloadKey = 0, emptyTitle, emptyDescription, forbiddenText, note, initialPeriod = "month" }: Props) {
  const [period, setPeriod] = useState<PeriodPreset>(initialPeriod);
  const [attempt, setAttempt] = useState(0);
  // The last good data stays on screen while the next period loads ("refetch keeps the frame").
  const [state, setState] = useState<{ key: string | null; data: Dynamics | null; error: "forbidden" | "failed" | null }>({ key: null, data: null, error: null });
  const key = `${period}|${reloadKey}|${attempt}`;

  useEffect(() => {
    let alive = true;
    load(period)
      .then((data) => alive && setState({ key, data, error: null }))
      .catch((e) => alive && setState((s) => ({ key, data: s.data, error: e instanceof ApiError && e.status === 403 ? "forbidden" : "failed" })));
    return () => {
      alive = false;
    };
  }, [load, period, key]);

  const loading = state.key !== key;
  const { data, error } = state;

  if (error === "forbidden") return <Alert tone="info">{forbiddenText ?? "Динамика дневника недоступна."}</Alert>;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Период">
        {PERIODS.map((p) => (
          <Chip key={p.value} active={period === p.value} onClick={() => setPeriod(p.value)}>
            {p.label}
          </Chip>
        ))}
      </div>

      {error === "failed" && !loading && (
        <Alert tone="danger" title="Не удалось загрузить динамику">
          <Button variant="secondary" size="sm" className="mt-2" onClick={() => setAttempt((a) => a + 1)}>
            Повторить
          </Button>
        </Alert>
      )}

      {!data && loading && <div className="h-64 animate-pulse rounded-2xl bg-sunken" aria-label="Загрузка" />}

      {data && (
        <div className={clsx("grid gap-4 transition-opacity", loading && "opacity-60")} aria-busy={loading}>
          {note?.(data)}
          {data.summary.entries === 0 ? (
            <EmptyState title={emptyTitle} description={emptyDescription} />
          ) : (
            <>
              <div className="grid grid-cols-3 gap-3">
                <Stat label="Отметок" value={String(data.summary.entries)} />
                <Stat
                  label="Среднее настроение"
                  value={`${formatMood(data.summary.avg_mood)} ${moodFor(data.summary.avg_mood)?.emoji ?? ""}`}
                  hint={moodFor(data.summary.avg_mood)?.label}
                />
                <Stat label="Дней с отметками" value={String(data.summary.days_with_entries)} />
              </div>
              <Card className="pt-8">
                <h3 className="mb-3 font-semibold">Настроение по {data.group === "week" ? "неделям" : "дням"}</h3>
                <MoodChart dynamics={data} />
                <p className="mt-2 text-xs text-muted">Наведите на график или выберите его и используйте стрелки, чтобы увидеть значения.</p>
                <DynamicsTable dynamics={data} />
              </Card>
              <Card>
                <h3 className="mb-4 font-semibold">Частые эмоции</h3>
                <TagBars tags={data.tags} />
              </Card>
            </>
          )}
        </div>
      )}
    </div>
  );
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-2xl border border-line bg-surface px-3 py-3 sm:px-4">
      <p className="text-xs text-muted sm:text-sm">{label}</p>
      <p className="mt-1 text-xl font-semibold sm:text-2xl">{value}</p>
      {hint && <p className="text-xs text-ink-2 sm:text-sm">{hint}</p>}
    </div>
  );
}
