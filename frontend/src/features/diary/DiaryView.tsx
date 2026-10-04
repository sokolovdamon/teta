"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert, Badge, Button, Card, EmptyState } from "@/components/ui";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { DynamicsPanel } from "./DynamicsPanel";
import { EntryForm } from "./EntryForm";
import { MOODS } from "./moods";
import type { DiaryEntry, Dynamics, EmotionTag, PeriodPreset } from "./types";

/** CL-06: add an entry, dynamics and the history of entries. */
export function DiaryView({ timezone }: { timezone: string }) {
  const [tags, setTags] = useState<EmotionTag[]>([]);
  const [reloadKey, setReloadKey] = useState(0);
  const [adding, setAdding] = useState(false);
  const [justSaved, setJustSaved] = useState(false);

  useEffect(() => {
    api<{ data: EmotionTag[] }>("/diary/emotion-tags")
      .then((r) => setTags(r.data))
      .catch(() => setTags([]));
  }, []);

  const load = useCallback((period: PeriodPreset) => api<{ data: Dynamics }>("/diary/dynamics", { query: { period } }).then((r) => r.data), []);

  return (
    <div className="grid gap-8">
      <section aria-labelledby="diary-add" className="grid gap-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="diary-add" className="text-xl font-semibold">
            Новая запись
          </h2>
          {!adding && (
            <Button
              onClick={() => {
                setAdding(true);
                setJustSaved(false);
              }}
            >
              Отметить настроение
            </Button>
          )}
        </div>
        {justSaved && <Alert tone="success">Запись сохранена.</Alert>}
        {adding && (
          <Card>
            <EntryForm
              tags={tags}
              onSaved={() => {
                setAdding(false);
                setJustSaved(true);
                setReloadKey((k) => k + 1);
              }}
              secondary={
                <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                  Отмена
                </Button>
              }
            />
          </Card>
        )}
      </section>

      <section aria-labelledby="diary-dynamics" className="grid gap-3">
        <h2 id="diary-dynamics" className="text-xl font-semibold">
          Динамика
        </h2>
        <DynamicsPanel
          load={load}
          reloadKey={reloadKey}
          emptyTitle="За этот период отметок нет"
          emptyDescription="Отмечайте настроение хотя бы раз в день — так динамика станет заметнее."
        />
      </section>

      <section aria-labelledby="diary-history" className="grid gap-3">
        <h2 id="diary-history" className="text-xl font-semibold">
          История записей
        </h2>
        <EntriesList timezone={timezone} reloadKey={reloadKey} onChanged={() => setReloadKey((k) => k + 1)} />
      </section>
    </div>
  );
}

function EntriesList({ timezone, reloadKey, onChanged }: { timezone: string; reloadKey: number; onChanged: () => void }) {
  const [items, setItems] = useState<DiaryEntry[] | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

  const fetchPage = useCallback((p: number) => api<Paginated<DiaryEntry>>("/diary/entries", { query: { page: p } }), []);

  useEffect(() => {
    let alive = true;
    fetchPage(1)
      .then((r) => {
        if (!alive) return;
        setItems(r.data);
        setPage(1);
        setLastPage(r.meta.last_page);
        setError(false);
      })
      .catch(() => alive && setError(true));
    return () => {
      alive = false;
    };
  }, [fetchPage, reloadKey]);

  async function more() {
    setLoadingMore(true);
    try {
      const r = await fetchPage(page + 1);
      setItems((prev) => [...(prev ?? []), ...r.data]);
      setPage(page + 1);
      setLastPage(r.meta.last_page);
    } finally {
      setLoadingMore(false);
    }
  }

  async function remove(id: string) {
    if (!window.confirm("Удалить запись из дневника? Это действие нельзя отменить.")) return;
    await api(`/diary/entries/${id}`, { method: "DELETE" });
    onChanged();
  }

  if (error) return <Alert tone="danger">Не удалось загрузить записи. Обновите страницу.</Alert>;
  if (items === null) return <p className="text-muted">Загрузка…</p>;
  if (items.length === 0) return <EmptyState title="Записей пока нет" description="Первая отметка займёт меньше минуты." />;

  return (
    <div className="grid gap-3">
      <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
        {items.map((e) => {
          const mood = MOODS[e.mood - 1];
          return (
            <li key={e.id} className="grid grid-cols-[auto_1fr_auto] items-start gap-3 px-4 py-4 sm:px-5">
              <span className="text-3xl leading-none" role="img" aria-label={mood?.label}>
                {mood?.emoji}
              </span>
              <div className="grid gap-1.5">
                <p className="flex flex-wrap items-baseline gap-x-2">
                  <span className="font-medium">{mood?.label}</span>
                  <time className="text-sm text-muted">{dateTime(e.recorded_at, timezone)}</time>
                </p>
                {e.tags.length > 0 && (
                  <div className="flex flex-wrap gap-1.5">
                    {e.tags.map((t) => (
                      <Badge key={t.id}>{t.title}</Badge>
                    ))}
                  </div>
                )}
                {e.note && <p className="whitespace-pre-line text-ink-2">{e.note}</p>}
              </div>
              <button type="button" onClick={() => void remove(e.id)} className="text-sm text-muted hover:text-danger" aria-label="Удалить запись">
                Удалить
              </button>
            </li>
          );
        })}
      </ul>
      {page < lastPage && (
        <div>
          <Button variant="secondary" onClick={() => void more()} loading={loadingMore}>
            Показать ещё
          </Button>
        </div>
      )}
    </div>
  );
}
