"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert, Badge, Button, Chip, EmptyState, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { date, dateTime, plural } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { RELATION_LABELS, RELATION_TONES } from "./access";
import type { ClientRow, RelationStatus } from "./types";

const FILTERS: { value: RelationStatus | ""; label: string }[] = [
  { value: "", label: "Все" },
  { value: "active", label: "Есть запись" },
  { value: "no_upcoming", label: "Нет записи" },
  { value: "finished", label: "Работа завершена" },
  { value: "changed", label: "Сменили психолога" },
];

/** PRO-05: clients of the psychologist with search and status filter. Contacts are never shown (BR-PSY-05). */
export function ClientsList({ timezone }: { timezone: string }) {
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<RelationStatus | "">("");
  const [page, setPage] = useState(1);
  const [loaded, setLoaded] = useState<{ key: string; res: Paginated<ClientRow> } | null>(null);
  const [failed, setFailed] = useState(false);
  const key = JSON.stringify([query, status, page]);
  const res = loaded?.res ?? null;
  const loading = loaded?.key !== key;

  useEffect(() => {
    const t = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    let alive = true;
    api<Paginated<ClientRow>>("/pro/clients", { query: { search: query, status, page } })
      .then((r) => {
        if (!alive) return;
        setLoaded({ key, res: r });
        setFailed(false);
      })
      .catch(() => alive && setFailed(true));
    return () => {
      alive = false;
    };
  }, [query, status, page, key]);

  const filtered = query !== "" || status !== "";

  return (
    <div className="grid gap-4">
      <div className="grid gap-3">
        <Input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Поиск по имени или псевдониму" aria-label="Поиск клиента" className="sm:max-w-sm" />
        <div className="flex flex-wrap gap-2" role="group" aria-label="Статус">
          {FILTERS.map((f) => (
            <Chip
              key={f.value || "all"}
              active={status === f.value}
              onClick={() => {
                setStatus(f.value);
                setPage(1);
              }}
            >
              {f.label}
            </Chip>
          ))}
        </div>
      </div>

      {failed && <Alert tone="danger">Не удалось загрузить список клиентов. Обновите страницу.</Alert>}
      {!res && !failed && <p className="text-muted">Загрузка…</p>}
      {res && res.data.length === 0 && (
        <EmptyState
          title={filtered ? "Никого не нашли" : "Клиентов пока нет"}
          description={filtered ? "Попробуйте изменить поиск или фильтр." : "Клиенты появятся здесь после первой записи к вам."}
        />
      )}

      {res && res.data.length > 0 && (
        <ul className={loading ? "grid gap-3 opacity-60" : "grid gap-3"} aria-busy={loading}>
          {res.data.map((c) => (
            <li key={c.client_id}>
              <Link
                href={`/pro/clients/${c.client_id}`}
                className="grid gap-3 rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-brand-tint sm:grid-cols-[1.4fr_1fr_1fr_auto] sm:items-center sm:p-5"
              >
                <div className="grid gap-1">
                  <p className="text-lg font-medium">{c.name}</p>
                  <div className="flex flex-wrap gap-1.5">
                    <Badge tone={RELATION_TONES[c.status]}>{RELATION_LABELS[c.status]}</Badge>
                    {c.has_pair_sessions && <Badge>Парные сессии</Badge>}
                  </div>
                </div>
                <div className="text-sm">
                  <p className="text-muted">Следующая сессия</p>
                  <p>{c.next_session_at ? dateTime(c.next_session_at, timezone) : "—"}</p>
                </div>
                <div className="text-sm">
                  <p className="text-muted">Проведено</p>
                  <p>
                    {c.held_count} {plural(c.held_count, ["сессия", "сессии", "сессий"])}
                    {c.last_session_at && <span className="text-muted"> · последняя {date(c.last_session_at, timezone)}</span>}
                  </p>
                </div>
                <span className="text-sm text-brand">Карточка →</span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {res && res.meta.last_page > 1 && (
        <nav className="flex items-center gap-3" aria-label="Страницы">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Назад
          </Button>
          <span className="text-sm text-muted">
            {page} из {res.meta.last_page}
          </span>
          <Button variant="secondary" size="sm" disabled={page >= res.meta.last_page} onClick={() => setPage(page + 1)}>
            Дальше
          </Button>
        </nav>
      )}
    </div>
  );
}
