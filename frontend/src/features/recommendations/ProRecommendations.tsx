"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, Modal } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { date, dateTime, plural } from "@/lib/format";
import { STATUS_LABELS, STATUS_TONES, TYPE_LABELS } from "./labels";
import { RecoContent } from "./RecoContent";
import { RecoForm } from "./RecoForm";
import type { EligibleSession, Recommendation } from "./types";

type ListResponse = { data: Recommendation[]; sessions: EligibleSession[]; window_days: number };

/** PRO-07: recommendations for one client — editor, statuses, revoke before the client opened it. */
export function ProRecommendations({ clientId, timezone, canRecommend }: { clientId: string; timezone: string; canRecommend: boolean }) {
  const [res, setRes] = useState<ListResponse | null>(null);
  const [failed, setFailed] = useState(false);
  const [editing, setEditing] = useState<Recommendation | "new" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [revoking, setRevoking] = useState<Recommendation | null>(null);

  const load = useCallback(
    () =>
      api<ListResponse>(`/pro/clients/${clientId}/recommendations`)
        .then((r) => {
          setRes(r);
          setFailed(false);
        })
        .catch(() => setFailed(true)),
    [clientId],
  );

  useEffect(() => {
    void load();
  }, [load]);

  async function act(reco: Recommendation, action: "send" | "revoke" | "delete") {
    setActionError(null);
    try {
      if (action === "delete") await api(`/pro/recommendations/${reco.id}`, { method: "DELETE" });
      else await api(`/pro/recommendations/${reco.id}/${action}`, { method: "POST" });
      setNotice(action === "send" ? "Рекомендация отправлена. Клиент получит уведомление без её текста." : action === "revoke" ? "Рекомендация отозвана." : "Черновик удалён.");
      await load();
    } catch (e) {
      setActionError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось выполнить действие.");
    }
  }

  if (failed) return <Alert tone="danger">Не удалось загрузить рекомендации. Обновите страницу.</Alert>;
  if (!res) return <p className="text-muted">Загрузка…</p>;

  const canCreate = canRecommend && res.sessions.length > 0;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-muted">
          Рекомендацию можно прикрепить к проведённой сессии в течение {res.window_days} {plural(res.window_days, ["дня", "дней", "дней"])} после неё.
          Отозвать отправленную можно, пока клиент её не открыл.
        </p>
        {editing === null && (
          <Button onClick={() => setEditing("new")} disabled={!canCreate}>
            Новая рекомендация
          </Button>
        )}
      </div>
      {!canRecommend && <Alert tone="info">Клиент сменил психолога: новые рекомендации отправить нельзя.</Alert>}
      {canRecommend && res.sessions.length === 0 && editing === null && (
        <Alert tone="info">Сейчас нет проведённых сессий, к которым можно прикрепить рекомендацию.</Alert>
      )}
      {notice && <Alert tone="success">{notice}</Alert>}
      {actionError && <Alert tone="danger">{actionError}</Alert>}

      {editing !== null && (
        <Card>
          <h3 className="mb-4 text-lg font-semibold">{editing === "new" ? "Новая рекомендация" : "Черновик"}</h3>
          <RecoForm
            sessions={res.sessions}
            timezone={timezone}
            draft={editing === "new" ? null : editing}
            onCancel={() => setEditing(null)}
            onDone={(reco) => {
              setEditing(null);
              setNotice(reco.status === "sent" ? "Рекомендация отправлена. Клиент получит уведомление без её текста." : "Черновик сохранён.");
              void load();
            }}
          />
        </Card>
      )}

      {res.data.length === 0 ? (
        <EmptyState title="Рекомендаций пока нет" description="Задания, упражнения и материалы помогают клиенту между сессиями." />
      ) : (
        <ul className="grid gap-3">
          {res.data.map((r) => (
            <li key={r.id}>
              <Card className="grid gap-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge>{TYPE_LABELS[r.type]}</Badge>
                  <Badge tone={STATUS_TONES[r.status]}>{STATUS_LABELS[r.status]}</Badge>
                  {r.due_date && <span className="text-sm text-muted">до {date(r.due_date, "UTC")}</span>}
                </div>
                <p className="text-lg font-medium">{r.title}</p>
                <p className="text-sm text-muted">{statusLine(r, timezone)}</p>
                <details>
                  <summary className="cursor-pointer text-sm text-brand">Показать содержание</summary>
                  <div className="mt-3">
                    <RecoContent reco={r} />
                  </div>
                </details>
                {(r.editable || r.can_revoke) && (
                  <div className="flex flex-wrap gap-2 border-t border-line pt-3">
                    {r.editable && (
                      <>
                        <Button size="sm" onClick={() => void act(r, "send")} disabled={!canRecommend}>
                          Отправить
                        </Button>
                        <Button size="sm" variant="secondary" onClick={() => setEditing(r)}>
                          Изменить
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => window.confirm("Удалить черновик?") && void act(r, "delete")}>
                          Удалить
                        </Button>
                      </>
                    )}
                    {r.can_revoke && (
                      <Button size="sm" variant="secondary" onClick={() => setRevoking(r)}>
                        Отозвать
                      </Button>
                    )}
                  </div>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}

      <Modal
        open={revoking !== null}
        onClose={() => setRevoking(null)}
        title="Отозвать рекомендацию?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setRevoking(null)}>
              Оставить
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                if (revoking) void act(revoking, "revoke");
                setRevoking(null);
              }}
            >
              Отозвать
            </Button>
          </>
        }
      >
        <p className="text-ink-2">Клиент ещё не открыл рекомендацию. После отзыва она исчезнет из его кабинета.</p>
      </Modal>
    </div>
  );
}

function statusLine(r: Recommendation, tz: string): string {
  const parts: string[] = [];
  if (r.session_starts_at) parts.push(`после сессии ${dateTime(r.session_starts_at, tz)}`);
  if (r.status === "draft" && r.window_until) parts.push(`отправить можно до ${dateTime(r.window_until, tz)}`);
  if (r.sent_at) parts.push(`отправлена ${dateTime(r.sent_at, tz)}`);
  if (r.viewed_at) parts.push(`открыта ${dateTime(r.viewed_at, tz)}`);
  if (r.done_at) parts.push(`выполнена ${dateTime(r.done_at, tz)}`);
  if (r.revoked_at) parts.push(`отозвана ${dateTime(r.revoked_at, tz)}`);
  return parts.join(" · ");
}
