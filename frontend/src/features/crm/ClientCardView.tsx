"use client";

import { clsx } from "clsx";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { Alert, Badge, Button, Card, EmptyState, Modal } from "@/components/ui";
import { DynamicsPanel } from "@/features/diary/DynamicsPanel";
import type { Dynamics, PeriodPreset } from "@/features/diary/types";
import { ProRecommendations } from "@/features/recommendations/ProRecommendations";
import { ApiError, api } from "@/lib/api";
import { date, dateTime, plural } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { RELATION_LABELS, RELATION_TONES, SESSION_STATUS_LABELS, TABS, diaryAccessText, finishBlockReason, type TabId } from "./access";
import { NotesTab } from "./NotesTab";
import type { ClientCardData, SessionRow } from "./types";

type Props = { initial: ClientCardData; timezone: string; initialTab: TabId };

/** PRO-05 card with PRO-06 dynamics and PRO-07 recommendations inside. */
export function ClientCardView({ initial, timezone, initialTab }: Props) {
  const [card, setCard] = useState(initial);
  const [tab, setTab] = useState<TabId>(initialTab);
  const [sessions, setSessions] = useState<SessionRow[] | null>(null);
  const [sessionsFailed, setSessionsFailed] = useState(false);
  const [confirmFinish, setConfirmFinish] = useState(false);
  const [finishError, setFinishError] = useState<string | null>(null);
  const [finishing, setFinishing] = useState(false);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const clientId = card.client_id;

  useEffect(() => {
    api<Paginated<SessionRow>>(`/pro/clients/${clientId}/sessions`)
      .then((r) => setSessions(r.data))
      .catch(() => setSessionsFailed(true));
  }, [clientId]);

  const loadDynamics = useCallback(
    (period: PeriodPreset) => api<{ data: Dynamics }>(`/pro/clients/${clientId}/diary`, { query: { period } }).then((r) => r.data),
    [clientId],
  );

  function select(next: TabId) {
    setTab(next);
    const url = new URL(window.location.href);
    url.searchParams.set("tab", next);
    window.history.replaceState(null, "", url);
  }

  function onTabKey(e: KeyboardEvent<HTMLDivElement>) {
    const i = TABS.findIndex((t) => t.id === tab);
    const next = e.key === "ArrowRight" ? (i + 1) % TABS.length : e.key === "ArrowLeft" ? (i - 1 + TABS.length) % TABS.length : null;
    if (next === null) return;
    e.preventDefault();
    select(TABS[next].id);
    tabRefs.current[TABS[next].id]?.focus();
  }

  async function finish() {
    setFinishing(true);
    setFinishError(null);
    try {
      await api(`/pro/clients/${clientId}/finish`, { method: "POST" });
      const fresh = await api<{ data: ClientCardData }>(`/pro/clients/${clientId}`);
      setCard(fresh.data);
      setConfirmFinish(false);
    } catch (e) {
      setFinishError(e instanceof ApiError ? e.message : "Не удалось сохранить отметку.");
    } finally {
      setFinishing(false);
    }
  }

  const accessText = diaryAccessText(card.diary, card.status, timezone);
  const blockReason = finishBlockReason(card);

  return (
    <div className="grid gap-6">
      <Link href="/pro/clients" className="text-sm text-brand">
        ← Все клиенты
      </Link>

      <Card className="grid gap-4">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="grid gap-2">
            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{card.name}</h1>
            <div className="flex flex-wrap gap-1.5">
              <Badge tone={RELATION_TONES[card.status]}>{RELATION_LABELS[card.status]}</Badge>
              {card.has_pair_sessions && <Badge>Парные сессии</Badge>}
            </div>
          </div>
          <div className="grid justify-items-start gap-1 sm:justify-items-end">
            <Button variant="secondary" onClick={() => setConfirmFinish(true)} disabled={blockReason !== null}>
              Работа завершена
            </Button>
            {blockReason && card.status !== "finished" && <p className="max-w-xs text-sm text-muted sm:text-right">{blockReason}</p>}
          </div>
        </div>
        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <Info label="Первая сессия" value={card.first_session_at ? date(card.first_session_at, timezone) : "—"} />
          <Info label="Проведено" value={`${card.held_count} ${plural(card.held_count, ["сессия", "сессии", "сессий"])}`} />
          <Info label="Следующая сессия" value={card.next_session_at ? dateTime(card.next_session_at, timezone) : "—"} />
          <Info label="Часовой пояс клиента" value={card.timezone} />
        </dl>
        {card.requests.length > 0 && (
          <div className="grid gap-2 border-t border-line pt-4">
            <p className="text-sm font-medium text-ink-2">Запросы, которые клиент выбрал при записи</p>
            <div className="flex flex-wrap gap-1.5">
              {card.requests.map((r) => (
                <Badge key={r.id} tone="brand">
                  {r.title}
                </Badge>
              ))}
            </div>
            <p className="text-xs text-muted">Сведения о состоянии: их видите только вы.</p>
          </div>
        )}
      </Card>

      <div>
        <div role="tablist" aria-label="Разделы карточки" className="mb-6 flex gap-1 overflow-x-auto border-b border-line" onKeyDown={onTabKey}>
          {TABS.map((t) => (
            <button
              key={t.id}
              ref={(el) => {
                tabRefs.current[t.id] = el;
              }}
              type="button"
              role="tab"
              id={`tab-${t.id}`}
              aria-selected={tab === t.id}
              aria-controls={`panel-${t.id}`}
              tabIndex={tab === t.id ? 0 : -1}
              onClick={() => select(t.id)}
              className={clsx(
                "-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-[15px]",
                tab === t.id ? "border-brand font-medium text-brand" : "border-transparent text-ink-2 hover:text-ink",
              )}
            >
              {t.label}
            </button>
          ))}
        </div>

        <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
          {tab === "sessions" && <SessionsList sessions={sessions} failed={sessionsFailed} timezone={timezone} />}
          {tab === "dynamics" &&
            (card.diary.available ? (
              <DynamicsPanel
                load={loadDynamics}
                emptyTitle="За этот период отметок нет"
                emptyDescription="Клиент отмечает настроение в дневнике эмоций по желанию."
                forbiddenText="Динамика дневника доступна психологу, к которому клиент записан или у которого проходил сессии."
                note={() => (accessText ? <Alert tone="info">{accessText}</Alert> : null)}
              />
            ) : (
              <Alert tone="info">{accessText}</Alert>
            ))}
          {tab === "recommendations" && <ProRecommendations clientId={clientId} timezone={timezone} canRecommend={card.can_recommend} />}
          {tab === "notes" && <NotesTab clientId={clientId} timezone={timezone} sessions={sessions ?? []} />}
        </div>
      </div>

      <Modal
        open={confirmFinish}
        onClose={() => setConfirmFinish(false)}
        title="Отметить работу завершённой?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmFinish(false)}>
              Отмена
            </Button>
            <Button onClick={() => void finish()} loading={finishing}>
              Работа завершена
            </Button>
          </>
        }
      >
        <div className="grid gap-3 text-ink-2">
          <p>После отметки вы будете видеть динамику дневника клиента только до сегодняшнего дня. Если клиент снова запишется к вам, доступ восстановится.</p>
          <p>Заметки останутся у вас и будут удалены через установленный срок хранения.</p>
          {finishError && <Alert tone="danger">{finishError}</Alert>}
        </div>
      </Modal>
    </div>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-muted">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}

function SessionsList({ sessions, failed, timezone }: { sessions: SessionRow[] | null; failed: boolean; timezone: string }) {
  if (failed) return <Alert tone="danger">Не удалось загрузить сессии. Обновите страницу.</Alert>;
  if (sessions === null) return <p className="text-muted">Загрузка…</p>;
  if (sessions.length === 0) return <EmptyState title="Сессий нет" />;
  return (
    <div className="grid gap-2">
      <p className="text-sm text-muted">Только ваши сессии с этим клиентом. Сессии с другими специалистами не показываются.</p>
      <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
        {sessions.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-5">
            <div>
              <p className="font-medium">{dateTime(s.starts_at, timezone)}</p>
              <p className="text-sm text-muted">
                {s.format === "pair" ? "Парная" : "Индивидуальная"} · {s.duration_min} мин
                {s.is_partner && " · клиент — второй участник"}
              </p>
            </div>
            <Badge tone={s.status === "held" ? "success" : s.status.startsWith("cancelled") ? "neutral" : s.status.includes("no_show") || s.status === "tech_issue" ? "warning" : "brand"}>
              {SESSION_STATUS_LABELS[s.status] ?? s.status}
            </Badge>
          </li>
        ))}
      </ul>
    </div>
  );
}
