"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Alert, Badge, Button, Card, Checkbox, EmptyState, Field, LinkButton, Modal, Textarea } from "@/components/ui";
import { rub } from "@/lib/format";
import { acceptInvitation, cancelSession, cancelTimeRequest, chooseAfterIssue, reschedule } from "./api";
import { CLIENT_STATUS, STATUS_TONE, cancelRuleText, choiceReason, paymentLine } from "./labels";
import { RescheduleDialog } from "./RescheduleDialog";
import { TimeRequestDialog } from "./TimeRequestDialog";
import { roomState, sessionDateTime, timeOf, untilText } from "./time";
import type { ClientSession, SessionsResponse, TimeRequest } from "./types";
import { errorCode, errorMessage, useResource } from "./useResource";

type Notice = { tone: "success" | "danger" | "info" | "warning"; text: string } | null;

const TIME_REQUEST_STATUS: Record<TimeRequest["status"], string> = {
  open: "Ждёт ответа психолога",
  offered: "Психолог предложил время",
  booked: "Вы записались",
  closed: "Закрыт",
};

/** CL-03: upcoming and past sessions, TetaMeet entry, reschedule, cancel, the choice after a psychologist's cancel, time requests. */
export function ClientSessions({ timezone, invite, choice }: { timezone: string; invite?: string | null; choice?: string | null }) {
  const router = useRouter();
  const [scope, setScope] = useState<"upcoming" | "past">("upcoming");
  const sessions = useResource<SessionsResponse>("/booking/sessions", { scope });
  const requests = useResource<{ data: TimeRequest[] }>("/booking/time-requests");
  const [notice, setNotice] = useState<Notice>(null);
  const [now, setNow] = useState(() => Date.now());
  const [rescheduleId, setRescheduleId] = useState<string | null>(null);
  const [cancelTarget, setCancelTarget] = useState<ClientSession | null>(null);
  const [choiceTarget, setChoiceTarget] = useState<string | null>(choice ?? null);
  const [freeRescheduleId, setFreeRescheduleId] = useState<string | null>(null);
  const [requestFor, setRequestFor] = useState<{ id: string; name: string } | null>(null);

  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 30_000);
    return () => clearInterval(t);
  }, []);

  const list = useMemo(() => sessions.data?.data ?? [], [sessions.data]);
  const invitations = sessions.data?.invitations ?? [];
  const choiceSession = useMemo(() => list.find((s) => s.id === choiceTarget) ?? null, [list, choiceTarget]);

  function done(text: string, tone: "success" | "info" = "success") {
    setNotice({ tone, text });
    sessions.reload();
    requests.reload();
  }

  async function accept(id: string) {
    try {
      await acceptInvitation(id);
      done("Приглашение принято: сессия появилась в списке.");
      if (invite) router.replace("/client/sessions");
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e, "Не удалось принять приглашение.") });
    }
  }

  async function refund(s: ClientSession) {
    try {
      await chooseAfterIssue(s.id, "refund");
      setChoiceTarget(null);
      done(s.is_corporate ? "Лимит корпоративной программы восстановлен." : `${rub(s.amount_charged ?? 0)} зачислено на баланс личного кабинета.`);
    } catch (e) {
      setNotice({ tone: "danger", text: errorMessage(e) });
    }
  }

  if (sessions.error && !sessions.data) {
    return (
      <Alert tone="danger" title="Сессии не загрузились">
        {sessions.error}{" "}
        <button type="button" className="text-brand underline" onClick={sessions.reload}>
          Повторить
        </button>
      </Alert>
    );
  }

  return (
    <div className="grid gap-6">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      {invitations.map((inv) => (
        <Alert key={inv.id} tone={inv.id === invite ? "warning" : "info"} title="Приглашение на парную сессию">
          <span>
            {inv.inviter ?? "Клиент"} приглашает вас на парную сессию с психологом {inv.psychologist}: {sessionDateTime(inv.starts_at, timezone)}.{" "}
          </span>
          <Button size="sm" className="mt-2" onClick={() => accept(inv.id)}>
            Принять приглашение
          </Button>
        </Alert>
      ))}
      {invite && sessions.data && !invitations.some((i) => i.id === invite) && !list.some((s) => s.id === invite) && (
        <Alert tone="warning">Приглашение не найдено: войдите с тем email, на который оно пришло, или оно уже принято.</Alert>
      )}

      <div className="flex flex-wrap items-center gap-2" role="tablist">
        <Button variant={scope === "upcoming" ? "primary" : "secondary"} size="sm" role="tab" aria-selected={scope === "upcoming"} onClick={() => setScope("upcoming")}>
          Предстоящие
        </Button>
        <Button variant={scope === "past" ? "primary" : "secondary"} size="sm" role="tab" aria-selected={scope === "past"} onClick={() => setScope("past")}>
          Прошедшие
        </Button>
        <span className="ml-auto text-sm text-muted">Время — в вашем часовом поясе ({timezone}).</span>
      </div>

      {sessions.loading && !sessions.data && <p className="text-muted">Загрузка…</p>}
      {sessions.data && list.length === 0 && (
        <EmptyState
          title={scope === "upcoming" ? "Предстоящих сессий нет" : "Прошедших сессий пока нет"}
          description={scope === "upcoming" ? "Подберите психолога или выберите время у своего специалиста." : undefined}
          action={scope === "upcoming" ? <LinkButton href="/psychologists">Найти психолога</LinkButton> : undefined}
        />
      )}

      <ul className="grid gap-4">
        {list.map((s) => (
          <li key={s.id}>
            <SessionCard
              s={s}
              tz={timezone}
              now={now}
              onReschedule={() => setRescheduleId(s.id)}
              onCancel={() => setCancelTarget(s)}
              onChoose={() => setChoiceTarget(s.id)}
              onRequestTime={() => s.psychologist && setRequestFor({ id: s.psychologist.id, name: s.psychologist.name })}
            />
          </li>
        ))}
      </ul>

      <TimeRequests items={requests.data?.data ?? []} tz={timezone} onCancel={async (id) => {
        try {
          await cancelTimeRequest(id);
          done("Запрос закрыт.", "info");
        } catch (e) {
          setNotice({ tone: "danger", text: errorMessage(e) });
        }
      }} />

      <RescheduleDialog
        sessionId={rescheduleId}
        timezone={timezone}
        onClose={() => setRescheduleId(null)}
        onConfirm={async (slot) => {
          await reschedule(rescheduleId!, slot);
          setRescheduleId(null);
          done(`Сессия перенесена на ${sessionDateTime(slot, timezone)}.`);
        }}
      />

      <RescheduleDialog
        sessionId={freeRescheduleId}
        timezone={timezone}
        title="Бесплатный перенос"
        confirmText="Записаться бесплатно"
        rule="Выберите любое свободное время этого психолога: оплата и чек перейдут на новую сессию, доплачивать не нужно."
        onClose={() => setFreeRescheduleId(null)}
        onConfirm={async (slot) => {
          await chooseAfterIssue(freeRescheduleId!, "reschedule", slot);
          setFreeRescheduleId(null);
          setChoiceTarget(null);
          done(`Новая сессия: ${sessionDateTime(slot, timezone)}. Оплата перенесена.`);
        }}
      />

      <CancelDialog
        session={cancelTarget}
        tz={timezone}
        onClose={() => setCancelTarget(null)}
        onDone={(text) => {
          setCancelTarget(null);
          done(text, "info");
        }}
        onReschedule={(id) => {
          setCancelTarget(null);
          setRescheduleId(id);
        }}
      />

      <Modal open={choiceSession !== null} onClose={() => setChoiceTarget(null)} title="Возврат или бесплатный перенос">
        {choiceSession && (
          <div className="grid gap-4">
            <p>{choiceReason(choiceSession.status)}</p>
            <p className="text-ink-2">
              Сессия {sessionDateTime(choiceSession.starts_at, timezone)} с психологом {choiceSession.psychologist?.name}. Если не выбрать до{" "}
              {choiceSession.choice_deadline_at ? sessionDateTime(choiceSession.choice_deadline_at, timezone) : "срока"}, оплата вернётся на баланс автоматически.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Card className="grid gap-2">
                <p className="font-medium">{choiceSession.is_corporate ? "Восстановить лимит" : `Вернуть ${rub(choiceSession.amount_charged ?? 0)} на баланс`}</p>
                <p className="text-sm text-muted">
                  {choiceSession.is_corporate ? "Сессия снова станет доступна по корпоративной программе." : "Баланс расходуется первым при следующей оплате; остаток можно вывести на карту."}
                </p>
                <Button variant="secondary" onClick={() => refund(choiceSession)}>
                  Вернуть на баланс
                </Button>
              </Card>
              <Card className="grid gap-2">
                <p className="font-medium">Бесплатный перенос</p>
                <p className="text-sm text-muted">Новое время у этого же психолога, без доплаты.</p>
                <Button onClick={() => setFreeRescheduleId(choiceSession.id)}>Выбрать время</Button>
              </Card>
            </div>
          </div>
        )}
      </Modal>

      <TimeRequestDialog
        psychologist={requestFor}
        onClose={() => setRequestFor(null)}
        onSent={() => {
          setRequestFor(null);
          done("Запрос отправлен психологу. Мы сообщим, когда он ответит.");
        }}
      />
    </div>
  );
}

function SessionCard({
  s,
  tz,
  now,
  onReschedule,
  onCancel,
  onChoose,
  onRequestTime,
}: {
  s: ClientSession;
  tz: string;
  now: number;
  onReschedule: () => void;
  onCancel: () => void;
  onChoose: () => void;
  onRequestTime: () => void;
}) {
  const room = roomState(s.room, now);
  const active = ["booked", "paid", "in_progress"].includes(s.status);
  const payment = paymentLine(s, tz);

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <p className="text-lg font-semibold">{sessionDateTime(s.starts_at, tz)}</p>
          <p className="text-ink-2">
            {s.format_label} · {s.duration_min} мин ·{" "}
            {s.psychologist ? (
              <Link href={`/psychologists/${s.psychologist.slug}`} className="text-brand hover:underline">
                {s.psychologist.name}
              </Link>
            ) : (
              "Психолог"
            )}
            {s.role === "partner" && " · вы — второй участник"}
          </p>
          {payment && <p className="text-sm text-muted">{payment}</p>}
          {s.partner && <p className="text-sm text-muted">Второй участник: {s.partner.email ?? "не указан"} — {s.partner.accepted ? "приглашение принято" : "ждём ответа"}</p>}
        </div>
        <Badge tone={STATUS_TONE[s.status]}>{CLIENT_STATUS[s.status]}</Badge>
      </div>

      {s.client_choice === "pending" && (
        <Alert tone="warning" title="Нужен ваш выбор">
          {choiceReason(s.status)} Верните оплату на баланс или перенесите сессию бесплатно.{" "}
          <Button size="sm" className="mt-2" onClick={onChoose}>
            Выбрать
          </Button>
        </Alert>
      )}
      {s.actions.pay && s.charge && (
        <Alert tone="danger" title="Оплата не прошла">
          Оплатите сессию другой картой до {s.charge.deadline_at ? sessionDateTime(s.charge.deadline_at, tz) : "начала"}, иначе запись отменится без удержаний.{" "}
          <Link className="font-medium text-brand underline" href={`/client/payments?pay=${s.charge.id}`}>
            Оплатить
          </Link>
        </Alert>
      )}

      {active && (
        <div className="flex flex-wrap items-center gap-3">
          {room === "open" && s.actions.join ? (
            <LinkButton href={s.room.url}>Войти в TetaMeet</LinkButton>
          ) : (
            <Button disabled>Войти в TetaMeet</Button>
          )}
          <span className="text-sm text-muted">
            {room === "not_yet"
              ? `Вход откроется ${untilText(s.room.opens_at, now)} — в ${timeOf(s.room.opens_at, tz)}`
              : room === "closed"
                ? "Вход закрыт"
                : "Вход открыт: проверьте камеру и микрофон"}
          </span>
        </div>
      )}

      {s.role === "client" && (s.actions.reschedule || s.actions.cancel || s.actions.complaint || active) && (
        <div className="flex flex-wrap gap-2 border-t border-line pt-4">
          {s.actions.reschedule && (
            <Button variant="secondary" size="sm" onClick={onReschedule}>
              Перенести
            </Button>
          )}
          {s.actions.cancel && (
            <Button variant="ghost" size="sm" onClick={onCancel}>
              Отменить
            </Button>
          )}
          {active && s.psychologist && (
            <Button variant="ghost" size="sm" onClick={onRequestTime}>
              Нет подходящего времени
            </Button>
          )}
          {s.actions.complaint && (
            <LinkButton variant="ghost" size="sm" href={`/client/payments?complaint=${s.id}#complaints`}>
              Жалоба на списание
            </LinkButton>
          )}
          {s.complaint && <span className="self-center text-sm text-muted">Жалоба: {s.complaint.status === "refunded" ? "возврат зачислен" : "рассматривается"}</span>}
        </div>
      )}
    </Card>
  );
}

function CancelDialog({
  session,
  tz,
  onClose,
  onDone,
  onReschedule,
}: {
  session: ClientSession | null;
  tz: string;
  onClose: () => void;
  onDone: (text: string) => void;
  onReschedule: (id: string) => void;
}) {
  const [reason, setReason] = useState("");
  const [agree, setAgree] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const late = !!session?.is_charged && !session.is_corporate;

  function close() {
    setReason("");
    setAgree(false);
    setError(null);
    onClose();
  }

  async function submit() {
    if (!session) return;
    setBusy(true);
    setError(null);
    try {
      const res = await cancelSession(session.id, { reason: reason.trim() || undefined, confirm_late: late ? agree : undefined });
      setReason("");
      setAgree(false);
      onDone(res.kind === "free_cancel" ? "Сессия отменена бесплатно." : res.refund_amount > 0 ? `Сессия отменена, на баланс возвращено ${rub(res.refund_amount)}.` : "Сессия отменена. Оплата не возвращается.");
    } catch (e) {
      setError(errorCode(e) === "late_cancel_confirmation" ? "Подтвердите, что понимаете: оплата не вернётся." : errorMessage(e, "Не удалось отменить сессию."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open={session !== null}
      onClose={close}
      title="Отмена сессии"
      footer={
        <>
          {late && session?.actions.reschedule && (
            <Button variant="secondary" onClick={() => session && onReschedule(session.id)}>
              Лучше перенести
            </Button>
          )}
          <Button variant="danger" onClick={submit} loading={busy} disabled={late && !agree}>
            Отменить сессию
          </Button>
        </>
      }
    >
      {session && (
        <div className="grid gap-4">
          <p>
            {sessionDateTime(session.starts_at, tz)}, психолог {session.psychologist?.name}.
          </p>
          <Alert tone={late ? "warning" : "info"}>{cancelRuleText(session, tz)}</Alert>
          <Field label="Причина" hint="Необязательно. Психолог причину не увидит.">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
          </Field>
          {late && <Checkbox label="Понимаю, что оплата за эту сессию не вернётся" checked={agree} onChange={(e) => setAgree(e.target.checked)} />}
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      )}
    </Modal>
  );
}

function TimeRequests({ items, tz, onCancel }: { items: TimeRequest[]; tz: string; onCancel: (id: string) => void }) {
  if (items.length === 0) return null;
  return (
    <section className="grid gap-3" aria-label="Запросы времени">
      <h2 className="text-lg font-semibold">Запросы «Нет подходящего времени»</h2>
      <ul className="grid gap-3">
        {items.map((r) => (
          <li key={r.id}>
            <Card className="grid gap-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{r.psychologist?.name}</p>
                <Badge tone={r.status === "offered" ? "success" : r.status === "open" ? "brand" : "neutral"}>{TIME_REQUEST_STATUS[r.status]}</Badge>
              </div>
              <p className="text-sm text-muted">Удобное время: {r.preferred_text}</p>
              {r.psychologist_comment && <p className="text-[15px]">Ответ психолога: {r.psychologist_comment}</p>}
              {r.offered_slots.length > 0 && (
                <p className="text-[15px]">Предложенное время: {r.offered_slots.map((s) => sessionDateTime(s, tz)).join("; ")}</p>
              )}
              <div className="flex flex-wrap gap-2">
                {r.status === "offered" && r.psychologist && (
                  <LinkButton size="sm" href={`/psychologists/${r.psychologist.slug}`}>
                    Записаться
                  </LinkButton>
                )}
                {(r.status === "open" || r.status === "offered") && (
                  <Button size="sm" variant="ghost" onClick={() => onCancel(r.id)}>
                    Закрыть запрос
                  </Button>
                )}
              </div>
            </Card>
          </li>
        ))}
      </ul>
    </section>
  );
}
