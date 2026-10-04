"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, LinkButton } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { date, dateTime } from "@/lib/format";
import { TYPE_LABELS, STATUS_TONES, clientStatusLabel } from "./labels";
import { RecoContent } from "./RecoContent";
import type { Recommendation } from "./types";

/** CL-05: opening a recommendation marks it viewed; the client marks it done manually. */
export function ClientRecommendationDetail({ id, timezone }: { id: string; timezone: string }) {
  const [reco, setReco] = useState<Recommendation | null>(null);
  const [error, setError] = useState<"missing" | "failed" | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let alive = true;
    api<{ data: Recommendation }>(`/client/recommendations/${id}`)
      .then((r) => alive && setReco(r.data))
      .catch((e) => alive && setError(e instanceof ApiError && e.status === 404 ? "missing" : "failed"));
    return () => {
      alive = false;
    };
  }, [id]);

  async function toggleDone() {
    if (!reco) return;
    setBusy(true);
    try {
      const r = await api<{ data: Recommendation }>(`/client/recommendations/${id}/${reco.status === "done" ? "undo" : "done"}`, { method: "POST" });
      setReco(r.data);
    } catch {
      setError("failed");
    } finally {
      setBusy(false);
    }
  }

  if (error === "missing") {
    return (
      <EmptyState
        title="Рекомендация недоступна"
        description="Возможно, психолог её отозвал или ссылка устарела."
        action={<LinkButton href="/client/recommendations" variant="secondary">Все рекомендации</LinkButton>}
      />
    );
  }
  if (!reco) return error ? <Alert tone="danger">Не удалось загрузить рекомендацию. Обновите страницу.</Alert> : <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid max-w-3xl gap-4">
      <Link href="/client/recommendations" className="text-sm text-brand">
        ← Все рекомендации
      </Link>
      <Card className="grid gap-5">
        <div className="grid gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge>{TYPE_LABELS[reco.type]}</Badge>
            <Badge tone={STATUS_TONES[reco.status]}>{clientStatusLabel(reco.status)}</Badge>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{reco.title}</h1>
          <p className="text-sm text-muted">
            {reco.psychologist?.name}
            {reco.session_starts_at && ` · после сессии ${dateTime(reco.session_starts_at, timezone)}`}
          </p>
          {reco.due_date && <p className="text-sm font-medium text-ink-2">Выполнить до {date(reco.due_date, "UTC")}</p>}
        </div>
        <RecoContent reco={reco} />
        {error === "failed" && <Alert tone="danger">Не получилось сохранить отметку. Попробуйте ещё раз.</Alert>}
        <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
          <Button onClick={() => void toggleDone()} loading={busy} variant={reco.status === "done" ? "secondary" : "primary"}>
            {reco.status === "done" ? "Вернуть в актуальные" : "Отметить выполненной"}
          </Button>
          {reco.done_at && reco.status === "done" && <span className="text-sm text-muted">Выполнено {dateTime(reco.done_at, timezone)}</span>}
        </div>
      </Card>
      <p className="text-sm text-muted">
        Переписки с психологом между сессиями нет: вопросы по заданию обсудите на следующей встрече. Организационные вопросы поможет решить{" "}
        <Link href="/client/chat" className="text-brand">
          бот Герман и поддержка
        </Link>
        .
      </p>
    </div>
  );
}
