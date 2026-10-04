"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert, Badge, Chip, EmptyState } from "@/components/ui";
import { api } from "@/lib/api";
import { date } from "@/lib/format";
import { TYPE_LABELS, clientStatusLabel, STATUS_TONES } from "./labels";
import type { Recommendation } from "./types";

type Filter = "active" | "done" | "all";
const FILTERS: { value: Filter; label: string }[] = [
  { value: "active", label: "Актуальные" },
  { value: "done", label: "Выполненные" },
  { value: "all", label: "Все" },
];

type ListResponse = { data: Recommendation[]; meta: { unread: number; total: number } };

/** CL-05: recommendations and tasks from the psychologist. */
export function ClientRecommendations({ timezone }: { timezone: string }) {
  const [filter, setFilter] = useState<Filter>("active");
  const [state, setState] = useState<{ filter: Filter; res: ListResponse } | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    api<ListResponse>("/client/recommendations", { query: { status: filter } })
      .then((res) => alive && setState({ filter, res }))
      .catch(() => alive && setFailed(true));
    return () => {
      alive = false;
    };
  }, [filter]);

  const loading = state?.filter !== filter;
  const items = state?.res.data ?? [];

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Фильтр">
        {FILTERS.map((f) => (
          <Chip key={f.value} active={filter === f.value} onClick={() => setFilter(f.value)}>
            {f.label}
          </Chip>
        ))}
      </div>
      {failed && <Alert tone="danger">Не удалось загрузить рекомендации. Обновите страницу.</Alert>}
      {!state && !failed && <p className="text-muted">Загрузка…</p>}
      {state && !loading && items.length === 0 && (
        <EmptyState
          title={filter === "done" ? "Выполненных пока нет" : "Рекомендаций пока нет"}
          description="После сессии психолог может оставить задание, упражнение или материал — они появятся здесь, а на почту придёт уведомление."
        />
      )}
      {items.length > 0 && (
        <ul className={loading ? "grid gap-3 opacity-60" : "grid gap-3"} aria-busy={loading}>
          {items.map((r) => (
            <li key={r.id}>
              <Link
                href={`/client/recommendations/${r.id}`}
                className="grid gap-2 rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-brand-tint sm:p-5"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <Badge>{TYPE_LABELS[r.type]}</Badge>
                  <Badge tone={STATUS_TONES[r.status]}>{clientStatusLabel(r.status)}</Badge>
                </div>
                <p className={r.status === "sent" ? "text-lg font-semibold" : "text-lg font-medium"}>{r.title}</p>
                <p className="text-sm text-muted">
                  {r.psychologist?.name}
                  {r.sent_at && ` · ${date(r.sent_at, timezone)}`}
                  {r.due_date && ` · выполнить до ${date(r.due_date, "UTC")}`}
                </p>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <p className="text-sm text-muted">Вопросы по заданиям обсудите с психологом на следующей сессии.</p>
    </div>
  );
}
