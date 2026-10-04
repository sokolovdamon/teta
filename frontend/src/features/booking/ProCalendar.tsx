"use client";

import { clsx } from "clsx";
import { useEffect, useMemo, useState } from "react";
import { Alert, Badge, Button, Card, Checkbox, EmptyState, Field, LinkButton, Modal, Textarea } from "@/components/ui";
import { rub } from "@/lib/format";
import { closeTimeRequest, offerTime, proCancel, reschedule, setOutcome } from "./api";
import { OUTCOME_LABELS, STATUS, STATUS_TONE } from "./labels";
import { RescheduleDialog } from "./RescheduleDialog";
import { SlotPicker } from "./SlotPicker";
import { addDaysKey, dayKey, mondayOf, roomState, sessionDateTime, shortDayLabel, timeOf, zoneLabel, zonedToUtc } from "./time";
import type { ProSession, TimeRequest } from "./types";
import { errorMessage, useResource } from "./useResource";

type View = "week" | "day" | "list";
type Notice = { tone: "success" | "danger" | "info"; text: string } | null;

/** PRO-04: calendar of bookings in the psychologist's timezone, session card with actions, time requests inbox. */
export function ProCalendar({ timezone, initialTab }: { timezone: string; initialTab?: "calendar" | "requests" }) {
  const [tab, setTab] = useState<"calendar" | "requests">(initialTab ?? "calendar");
  const [view, setView] = useState<View>("week");
  const [anchor, setAnchor] = useState(() => dayKey(new Date(), timezone));
  const [includeCancelled, setIncludeCancelled] = useState(false);
  const [notice, setNotice] = useState<Notice>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(t);
  }, []);

  const range = useMemo(() => {
    if (view === "day") return { from: anchor, days: 1 };
    if (view === "list") return { from: anchor, days: 28 };
    return { from: mondayOf(anchor), days: 7 };
  }, [view, anchor]);
  const fromIso = zonedToUtc(range.from, "00:00", timezone).toISOString();
  const toIso = zonedToUtc(addDaysKey(range.from, range.days), "00:00", timezone).toISOString();
  const sessions = useResource<{ data: ProSession[]; timezone: string; awaiting_outcome: number }>("/booking/pro/sessions", {
    from: fromIso,
    to: toIso,
    include_cancelled: includeCancelled ? 1 : 0,
  });
  const inbox = useResource<{ data: TimeRequest[]; open: number }>("/booking/pro/time-requests");
  const list = useMemo(() => sessions.data?.data ?? [], [sessions.data]);
  const current = list.find((s) => s.id === selected) ?? null;

  function shift(direction: number) {
    setAnchor((a) => addDaysKey(a, direction * (view === "day" ? 1 : 7)));
  }

  function done(text: string) {
    setNotice({ tone: "success", text });
    sessions.reload();
  }

  return (
    <div className="grid gap-6">
      <div className="flex flex-wrap gap-2" role="tablist">
        <Button variant={tab === "calendar" ? "primary" : "secondary"} size="sm" role="tab" aria-selected={tab === "calendar"} onClick={() => setTab("calendar")}>
          Календарь
        </Button>
        <Button variant={tab === "requests" ? "primary" : "secondary"} size="sm" role="tab" aria-selected={tab === "requests"} onClick={() => setTab("requests")}>
          Запросы времени{inbox.data && inbox.data.open > 0 ? ` (${inbox.data.open})` : ""}
        </Button>
      </div>

      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {tab === "requests" ? (
        <Inbox timezone={timezone} resource={inbox} onNotice={setNotice} />
      ) : (
        <>
          {(sessions.data?.awaiting_outcome ?? 0) > 0 && (
            <Alert tone="warning">
              Сессий без отметки итога: {sessions.data?.awaiting_outcome}. Отметьте «Проведена», «Клиент не пришёл» или «Техническая проблема» — иначе итог определится автоматически по журналу сессий.
            </Alert>
          )}
          <div className="flex flex-wrap items-center gap-2">
            {(["week", "day", "list"] as View[]).map((v) => (
              <Button key={v} size="sm" variant={view === v ? "primary" : "secondary"} onClick={() => setView(v)}>
                {v === "week" ? "Неделя" : v === "day" ? "День" : "Список"}
              </Button>
            ))}
            <span className="mx-2 h-6 w-px bg-line" aria-hidden />
            <Button size="sm" variant="ghost" onClick={() => shift(-1)} aria-label="Назад">
              ←
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setAnchor(dayKey(new Date(), timezone))}>
              Сегодня
            </Button>
            <Button size="sm" variant="ghost" onClick={() => shift(1)} aria-label="Вперёд">
              →
            </Button>
            <span className="text-sm text-ink-2">
              {shortDayLabel(range.from)} — {shortDayLabel(addDaysKey(range.from, range.days - 1))}
            </span>
            <Checkbox className="ml-auto" label="Показывать отменённые" checked={includeCancelled} onChange={(e) => setIncludeCancelled(e.target.checked)} />
          </div>
          <p className="text-sm text-muted">Время — в вашем часовом поясе ({zoneLabel(timezone)}).</p>

          {sessions.error && !sessions.data && <Alert tone="danger">{sessions.error}</Alert>}
          {!sessions.data && !sessions.error && <p className="text-muted">Загрузка…</p>}
          {sessions.data &&
            (view === "week" ? (
              <WeekGrid from={range.from} sessions={list} tz={timezone} onOpen={setSelected} />
            ) : list.length === 0 ? (
              <EmptyState title="Записей нет" description="Проверьте график работы: клиенты видят только открытое время." action={<LinkButton href="/pro/schedule" variant="secondary">График работы</LinkButton>} />
            ) : (
              <ul className="grid gap-3">
                {list.map((s) => (
                  <li key={s.id}>
                    <SessionRow s={s} tz={timezone} withDate onOpen={() => setSelected(s.id)} />
                  </li>
                ))}
              </ul>
            ))}
        </>
      )}

      <SessionModal session={current} tz={timezone} now={now} onClose={() => setSelected(null)} onDone={done} onError={(text) => setNotice({ tone: "danger", text })} />
    </div>
  );
}

function WeekGrid({ from, sessions, tz, onOpen }: { from: string; sessions: ProSession[]; tz: string; onOpen: (id: string) => void }) {
  const days = Array.from({ length: 7 }, (_, i) => addDaysKey(from, i));
  const today = dayKey(new Date(), tz);
  return (
    <div className="grid gap-3 md:grid-cols-7">
      {days.map((key) => {
        const items = sessions.filter((s) => dayKey(s.starts_at, tz) === key);
        return (
          <section key={key} className={clsx("grid content-start gap-2 rounded-2xl border p-2", key === today ? "border-brand" : "border-line bg-surface")} aria-label={shortDayLabel(key)}>
            <p className="px-1 text-sm font-medium capitalize text-ink-2">{shortDayLabel(key)}</p>
            {items.length === 0 && <p className="px-1 text-xs text-muted">—</p>}
            {items.map((s) => (
              <button
                key={s.id}
                type="button"
                onClick={() => onOpen(s.id)}
                className={clsx(
                  "grid gap-0.5 rounded-xl border px-2 py-1.5 text-left text-sm hover:border-brand-tint",
                  s.status.startsWith("cancelled") ? "border-line bg-sunken text-muted line-through" : "border-line bg-ground",
                )}
              >
                <span className="font-medium">
                  {timeOf(s.starts_at, tz)}–{timeOf(s.ends_at, tz)}
                </span>
                <span className="truncate">{s.client.name}</span>
                <Badge tone={STATUS_TONE[s.status]} className="justify-self-start">
                  {STATUS[s.status]}
                </Badge>
              </button>
            ))}
          </section>
        );
      })}
    </div>
  );
}

function SessionRow({ s, tz, withDate, onOpen }: { s: ProSession; tz: string; withDate?: boolean; onOpen: () => void }) {
  return (
    <button type="button" onClick={onOpen} className="w-full text-left">
      <Card className="flex flex-wrap items-center justify-between gap-3 hover:border-brand-tint">
        <div className="grid gap-0.5">
          <p className="font-medium">{withDate ? sessionDateTime(s.starts_at, tz) : `${timeOf(s.starts_at, tz)}–${timeOf(s.ends_at, tz)}`}</p>
          <p className="text-sm text-ink-2">
            {s.client.name} · {s.format_label} · {s.payment_status}
          </p>
        </div>
        <Badge tone={STATUS_TONE[s.status]}>{STATUS[s.status]}</Badge>
      </Card>
    </button>
  );
}

function SessionModal({
  session,
  tz,
  now,
  onClose,
  onDone,
  onError,
}: {
  session: ProSession | null;
  tz: string;
  now: number;
  onClose: () => void;
  onDone: (text: string) => void;
  onError: (text: string) => void;
}) {
  const [mode, setMode] = useState<"card" | "cancel">("card");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rescheduleId, setRescheduleId] = useState<string | null>(null);

  function close() {
    setMode("card");
    setReason("");
    setError(null);
    onClose();
  }

  async function run(action: () => Promise<unknown>, text: string) {
    setBusy(true);
    setError(null);
    try {
      await action();
      close();
      onDone(text);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const room = session ? roomState(session.room, now) : "closed";

  return (
    <>
      <Modal open={session !== null && rescheduleId === null} onClose={close} title={session ? `${session.client.name}: ${sessionDateTime(session.starts_at, tz)}` : ""}>
        {session && mode === "card" && (
          <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={STATUS_TONE[session.status]}>{STATUS[session.status]}</Badge>
              <span className="text-sm text-ink-2">
                {session.format_label} · {session.duration_min} мин · {session.payment_status}
                {session.price ? ` · ${rub(session.price)}` : ""}
              </span>
            </div>
            {session.format === "pair" && <p className="text-sm text-ink-2">Второй участник: {session.partner_joined ? "принял приглашение" : "ещё не принял приглашение"}</p>}
            <div className="grid gap-1">
              <p className="text-sm font-medium text-ink-2">Запросы, выбранные клиентом</p>
              {session.client_requests.length ? (
                <div className="flex flex-wrap gap-1.5">
                  {session.client_requests.map((r) => (
                    <Badge key={r} tone="brand">
                      {r}
                    </Badge>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted">Клиент не указал запросы.</p>
              )}
            </div>
            {(session.log.psychologist_joined_at || session.log.client_joined_at || session.log.joint_duration_sec) && (
              <div className="grid gap-0.5 text-sm text-ink-2">
                <p className="font-medium">Журнал сессии</p>
                <p>Вы подключились: {session.log.psychologist_joined_at ? timeOf(session.log.psychologist_joined_at, tz) : "—"}</p>
                <p>Клиент подключился: {session.log.client_joined_at ? timeOf(session.log.client_joined_at, tz) : "—"}</p>
                <p>Совместное время: {session.log.joint_duration_sec ? `${Math.round(session.log.joint_duration_sec / 60)} мин` : "—"}</p>
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              {room === "open" && session.actions.join ? <LinkButton href={session.room.url}>Начать TetaMeet</LinkButton> : null}
              {room === "not_yet" && ["paid", "booked"].includes(session.status) && (
                <Button disabled title="Вход откроется перед началом">
                  Начать TetaMeet
                </Button>
              )}
              {session.actions.reschedule && (
                <Button variant="secondary" onClick={() => setRescheduleId(session.id)}>
                  Перенести
                </Button>
              )}
              {session.actions.cancel && (
                <Button variant="ghost" onClick={() => setMode("cancel")}>
                  Отменить
                </Button>
              )}
            </div>
            {session.actions.outcome.length > 0 && (
              <div className="grid gap-2 border-t border-line pt-4">
                <p className="text-sm font-medium text-ink-2">Итог сессии</p>
                <div className="flex flex-wrap gap-2">
                  {session.actions.outcome.map((o) => (
                    <Button key={o} size="sm" variant={o === "held" ? "primary" : "secondary"} loading={busy} onClick={() => run(() => setOutcome(session.id, o), `Итог отмечен: ${OUTCOME_LABELS[o]}.`)}>
                      {OUTCOME_LABELS[o]}
                    </Button>
                  ))}
                </div>
                <p className="text-xs text-muted">«Клиент не пришёл» — не раньше чем через 15 минут после начала. «Проведена» — если по журналу вы были на связи с клиентом.</p>
              </div>
            )}
            {error && <Alert tone="danger">{error}</Alert>}
          </div>
        )}
        {session && mode === "cancel" && (
          <div className="grid gap-4">
            <Alert tone="warning">
              Клиент получит письмо. Если оплата уже списана, он выберет возврат на баланс или бесплатный перенос. Отмена позже чем за 12 часов до начала учитывается как инцидент качества.
            </Alert>
            <Field label="Причина отмены" hint="Клиент увидит, что сессия отменена психологом.">
              <Textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
            </Field>
            {error && <Alert tone="danger">{error}</Alert>}
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="secondary" onClick={() => setMode("card")}>
                Назад
              </Button>
              <Button variant="danger" loading={busy} disabled={reason.trim().length < 3} onClick={() => run(() => proCancel(session.id, reason.trim()), "Сессия отменена, клиент уведомлён.")}>
                Отменить сессию
              </Button>
            </div>
          </div>
        )}
      </Modal>
      <RescheduleDialog
        sessionId={rescheduleId}
        timezone={tz}
        pro
        onClose={() => setRescheduleId(null)}
        onConfirm={async (slot) => {
          try {
            await reschedule(rescheduleId!, slot, true);
            setRescheduleId(null);
            close();
            onDone(`Сессия перенесена на ${sessionDateTime(slot, tz)}. Клиент уведомлён.`);
          } catch (e) {
            onError(errorMessage(e));
            throw e;
          }
        }}
      />
    </>
  );
}

function Inbox({
  timezone,
  resource,
  onNotice,
}: {
  timezone: string;
  resource: ReturnType<typeof useResource<{ data: TimeRequest[]; open: number }>>;
  onNotice: (n: Notice) => void;
}) {
  const [offerFor, setOfferFor] = useState<TimeRequest | null>(null);
  const [closeFor, setCloseFor] = useState<TimeRequest | null>(null);
  const [slots, setSlots] = useState<string[]>([]);
  const [comment, setComment] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const free = useResource<{ data: string[]; timezone: string }>(offerFor ? "/booking/pro/free-slots" : null, { format: offerFor?.format });

  function reset() {
    setOfferFor(null);
    setCloseFor(null);
    setSlots([]);
    setComment("");
    setError(null);
  }

  async function submit(kind: "offer" | "close") {
    const target = kind === "offer" ? offerFor : closeFor;
    if (!target) return;
    setBusy(true);
    setError(null);
    try {
      if (kind === "offer") await offerTime(target.id, slots, comment.trim() || undefined);
      else await closeTimeRequest(target.id, comment.trim() || undefined);
      reset();
      onNotice({ tone: "success", text: kind === "offer" ? "Клиент получил ваше предложение." : "Запрос закрыт, клиент уведомлён." });
      resource.reload();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const items = resource.data?.data ?? [];
  if (resource.error && !resource.data) return <Alert tone="danger">{resource.error}</Alert>;
  if (!resource.data) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid gap-4">
      <p className="text-ink-2">
        Клиенты, которым не подошло ни одно свободное время, присылают удобные дни и часы. Откройте подходящее время в{" "}
        <a className="text-brand underline" href="/pro/schedule">
          графике работы
        </a>{" "}
        и предложите его — или закройте запрос.
      </p>
      {items.length === 0 && <EmptyState title="Новых запросов нет" />}
      <ul className="grid gap-3">
        {items.map((r) => (
          <li key={r.id}>
            <Card className="grid gap-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{r.client?.name ?? "Клиент"}</p>
                <Badge tone={r.status === "open" ? "warning" : "success"}>{r.status === "open" ? "Новый" : "Время предложено"}</Badge>
              </div>
              <p className="text-sm text-ink-2">Удобно клиенту: {r.preferred_text}</p>
              {r.comment && <p className="text-sm text-ink-2">Комментарий: {r.comment}</p>}
              {r.offered_slots.length > 0 && <p className="text-sm text-ink-2">Предложено: {r.offered_slots.map((s) => sessionDateTime(s, timezone)).join("; ")}</p>}
              <div className="flex flex-wrap gap-2">
                <Button size="sm" onClick={() => setOfferFor(r)}>
                  Предложить время
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setCloseFor(r)}>
                  Закрыть
                </Button>
              </div>
            </Card>
          </li>
        ))}
      </ul>

      <Modal
        open={offerFor !== null}
        onClose={reset}
        title="Предложить время"
        footer={
          <Button onClick={() => submit("offer")} loading={busy} disabled={slots.length === 0 && !comment.trim()}>
            Отправить клиенту
          </Button>
        }
      >
        <div className="grid gap-4">
          <p className="text-sm text-ink-2">Удобно клиенту: {offerFor?.preferred_text}</p>
          {free.data ? (
            <SlotPicker
              slots={free.data.data}
              timezone={timezone}
              value={null}
              onChange={() => undefined}
              multiple
              values={slots}
              onToggle={(slot) => setSlots((s) => (s.includes(slot) ? s.filter((x) => x !== slot) : [...s, slot]))}
              emptyText="Свободного времени на ближайшие две недели нет — откройте его в графике работы."
            />
          ) : (
            <p className="text-muted">Загружаем свободное время…</p>
          )}
          <Field label="Комментарий клиенту">
            <Textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={1000} />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Modal>

      <Modal
        open={closeFor !== null}
        onClose={reset}
        title="Закрыть запрос"
        footer={
          <Button variant="danger" onClick={() => submit("close")} loading={busy}>
            Закрыть запрос
          </Button>
        }
      >
        <div className="grid gap-4">
          <p>Клиент получит уведомление, что подходящего времени нет, и сможет подобрать другого специалиста.</p>
          <Field label="Комментарий (необязательно)">
            <Textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={1000} />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Modal>
    </div>
  );
}
