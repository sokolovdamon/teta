"use client";

import { clsx } from "clsx";
import { useMemo, useState } from "react";
import { Chip } from "@/components/ui";
import { groupSlotsByDay, timeOf, zoneLabel } from "./time";

type Props = {
  slots: string[];
  timezone: string;
  value: string | null;
  onChange: (slot: string) => void;
  /** Several slots can be selected (offer of time by the psychologist). */
  multiple?: boolean;
  values?: string[];
  onToggle?: (slot: string) => void;
  emptyText?: string;
};

/** Free slots grouped by day in the viewer's timezone. */
export function SlotPicker({ slots, timezone, value, onChange, multiple, values = [], onToggle, emptyText }: Props) {
  const days = useMemo(() => groupSlotsByDay(slots, timezone), [slots, timezone]);
  const [dayKey, setDayKey] = useState<string | null>(null);
  const active = days.find((d) => d.key === dayKey) ?? days[0];

  if (days.length === 0) {
    return <p className="text-muted">{emptyText ?? "Свободного времени нет. Попробуйте позже или отправьте запрос «Нет подходящего времени»."}</p>;
  }

  return (
    <div className="grid gap-3">
      <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Дни">
        {days.map((d) => (
          <button
            key={d.key}
            type="button"
            role="tab"
            aria-selected={d.key === active.key}
            onClick={() => setDayKey(d.key)}
            className={clsx(
              "shrink-0 rounded-xl border px-3 py-2 text-left text-sm",
              d.key === active.key ? "border-brand bg-brand-soft text-ink" : "border-line bg-surface text-ink-2 hover:border-brand-tint",
            )}
          >
            <span className="block font-medium capitalize">{d.label.split(",")[1]?.trim() ?? ""}</span>
            <span className="block text-muted">{d.label.split(",")[0]}</span>
          </button>
        ))}
      </div>
      <div className="flex flex-wrap gap-2">
        {active.slots.map((slot) => (
          <Chip
            key={slot}
            active={multiple ? values.includes(slot) : value === slot}
            onClick={() => (multiple ? onToggle?.(slot) : onChange(slot))}
          >
            {timeOf(slot, timezone)}
          </Chip>
        ))}
      </div>
      <p className="text-xs text-muted">Время указано в часовом поясе {zoneLabel(timezone)}.</p>
    </div>
  );
}
