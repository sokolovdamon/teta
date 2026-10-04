"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button, EmptyState } from "@/components/ui";
import { api } from "@/lib/api";
import { dateTime } from "@/lib/format";

type Item = { id: string; title: string; body: string | null; link: string | null; read_at: string | null; created_at: string };

/** CL-14: notification centre. */
export function NotificationsList({ timezone }: { timezone: string }) {
  const [items, setItems] = useState<Item[] | null>(null);

  const load = useCallback(() => api<{ data: Item[] }>("/notifications").then((r) => setItems(r.data)), []);
  useEffect(() => {
    load().catch(() => setItems([]));
  }, [load]);

  if (items === null) return <p className="text-muted">Загрузка…</p>;
  if (items.length === 0) return <EmptyState title="Уведомлений пока нет" description="Здесь появятся записи, напоминания и ответы поддержки." />;

  return (
    <div className="grid gap-4">
      <div>
        <Button variant="secondary" size="sm" onClick={() => api("/notifications/read-all", { method: "POST" }).then(load)}>
          Отметить все прочитанными
        </Button>
      </div>
      <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
        {items.map((n) => (
          <li key={n.id} className={n.read_at ? "px-5 py-4" : "bg-brand-soft/50 px-5 py-4"}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <p className={n.read_at ? "" : "font-medium"}>{n.title}</p>
              <time className="text-sm text-muted">{dateTime(n.created_at, timezone)}</time>
            </div>
            {n.body && <p className="mt-1 text-ink-2">{n.body}</p>}
            {n.link && (
              <Link
                href={n.link}
                className="mt-1 inline-block text-sm text-brand"
                onClick={() => !n.read_at && api(`/notifications/${n.id}/read`, { method: "POST" })}
              >
                Открыть
              </Link>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
