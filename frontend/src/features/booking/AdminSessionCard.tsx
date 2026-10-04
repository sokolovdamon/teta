"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Badge, Button, Card, Field, Modal, Select, Textarea } from "@/components/ui";
import { dateTime, rub } from "@/lib/format";
import { adminCancel, adminOutcome } from "./api";
import { OUTCOME_LABELS, STATUS, STATUS_TONE } from "./labels";
import { sessionDateTime, timeOf } from "./time";
import type { AdminSessionCard as CardData } from "./types";
import { errorMessage, useResource } from "./useResource";

const TZ = "Europe/Moscow";

const ENTITY: Record<string, string> = { therapy_session: "Сессия", charge_task: "Списание" };

/** ADM-04 card: session data, session log, payment and charge, history of transitions, outcome correction, platform cancel. */
export function AdminSessionCard({ id, canSetOutcome, canCancel }: { id: string; canSetOutcome: boolean; canCancel: boolean }) {
  const res = useResource<CardData>(`/admin/sessions/${id}`);
  const [dialog, setDialog] = useState<"outcome" | "cancel" | null>(null);
  const [outcome, setOutcomeValue] = useState("held");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [now] = useState(() => Date.now());

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      if (dialog === "outcome") await adminOutcome(id, outcome, reason.trim());
      else await adminCancel(id, reason.trim());
      setNotice(dialog === "outcome" ? "Итог сессии сохранён, запись в журнале аудита." : "Сессия отменена платформой, оплата зачислена клиенту на баланс.");
      setDialog(null);
      setReason("");
      res.reload();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  if (res.error && !res.data) return <Alert tone="danger">{res.error}</Alert>;
  if (!res.data) return <p className="text-muted">Загрузка…</p>;
  const { data: s, history, charge_task: task, payments, balance_operations: ops, complaints } = res.data;
  const finished = ["in_progress", "held", "client_no_show", "psy_no_show", "tech_issue"].includes(s.status);
  const cancellable = ["booked", "paid"].includes(s.status) && new Date(s.starts_at).getTime() > now;

  return (
    <div className="grid gap-6">
      {notice && <Alert tone="success">{notice}</Alert>}
      <div className="flex flex-wrap items-center gap-3">
        <Badge tone={STATUS_TONE[s.status]}>{STATUS[s.status]}</Badge>
        {s.client_choice === "pending" && <Badge tone="warning">Ждёт выбора клиента до {s.choice_deadline_at ? dateTime(s.choice_deadline_at, TZ) : "—"}</Badge>}
        {s.client_choice === "refund" && <Badge tone="neutral">Клиент выбрал возврат</Badge>}
        {s.client_choice === "reschedule" && <Badge tone="neutral">Клиент выбрал перенос</Badge>}
        <span className="ml-auto flex gap-2">
          {canSetOutcome && finished && (
            <Button size="sm" variant="secondary" onClick={() => setDialog("outcome")}>
              Итог по журналу
            </Button>
          )}
          {canCancel && cancellable && (
            <Button size="sm" variant="danger" onClick={() => setDialog("cancel")}>
              Отменить платформой
            </Button>
          )}
        </span>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="grid gap-1 text-[15px]">
          <p className="font-medium">Сессия</p>
          <p>{sessionDateTime(s.starts_at, TZ)}</p>
          <p className="text-ink-2">
            {s.format_label}, {s.duration_min} мин · источник: {s.source}
          </p>
          <p className="text-ink-2">Клиент: {s.client ? `${s.client.name} (${s.client.email})` : "—"}, пояс {s.client_timezone}</p>
          <p className="text-ink-2">Психолог: {s.psychologist?.name ?? "—"}</p>
          {s.partner && <p className="text-ink-2">Второй участник: {s.partner.email} {s.partner.user_id ? "(принял)" : "(не принял)"}</p>}
          {s.rescheduled_from_id && (
            <p>
              <Link className="text-brand underline" href={`/admin/sessions/${s.rescheduled_from_id}`}>
                Исходная сессия
              </Link>
            </p>
          )}
          {res.data.rescheduled_to && (
            <p>
              <Link className="text-brand underline" href={`/admin/sessions/${res.data.rescheduled_to}`}>
                Новая сессия (бесплатный перенос)
              </Link>
            </p>
          )}
          {s.cancel_reason && <p className="text-ink-2">Причина отмены: {s.cancel_reason}</p>}
        </Card>
        <Card className="grid gap-1 text-[15px]">
          <p className="font-medium">Журнал сессий (TetaMeet)</p>
          <p className="text-ink-2">Психолог подключился: {s.log.psychologist_joined_at ? timeOf(s.log.psychologist_joined_at, TZ) : "—"}</p>
          <p className="text-ink-2">Клиент подключился: {s.log.client_joined_at ? timeOf(s.log.client_joined_at, TZ) : "—"}</p>
          <p className="text-ink-2">Совместное время: {s.log.joint_duration_sec !== null ? `${Math.round(s.log.joint_duration_sec / 60)} мин` : "—"}</p>
          <p className="text-ink-2">Фактическая длительность: {s.log.actual_duration_sec !== null ? `${Math.round(s.log.actual_duration_sec / 60)} мин` : "—"}</p>
          {s.outcome_source && <p className="text-sm text-muted">Итог: {s.outcome_source === "auto" ? "автоматически" : s.outcome_source === "admin" ? "администратором" : "психологом"}</p>}
        </Card>
        <Card className="grid gap-1 text-[15px]">
          <p className="font-medium">Оплата</p>
          <p className="text-ink-2">
            Цена {rub(s.price)}
            {s.discount > 0 ? `, скидка ${rub(s.discount)}` : ""} · к оплате {rub(s.amount_due)}
          </p>
          <p className="text-ink-2">
            Списано {rub(s.amount_charged)}: карта {rub(s.paid.card)}, баланс {rub(s.paid.balance)}
            {s.paid.certificate ? ` (сертификат ${rub(s.paid.certificate)})` : ""}
          </p>
          {s.balance_refunded ? <p className="text-ink-2">Возвращено клиенту: {rub(s.balance_refunded)}</p> : null}
          <p className="text-ink-2">{s.payment_status}</p>
          {task && (
            <p className="text-sm text-muted">
              Списание: {task.status}, попыток {task.attempts}, срок {task.due_at ? dateTime(task.due_at, TZ) : "—"}, крайний срок {task.deadline_at ? dateTime(task.deadline_at, TZ) : "—"}
              {task.last_error_code ? `, ошибка ${task.last_error_code}` : ""}
            </p>
          )}
        </Card>
      </div>

      {(payments.length > 0 || ops.length > 0 || complaints.length > 0) && (
        <Card className="grid gap-2 text-[15px]">
          <p className="font-medium">Деньги по сессии</p>
          {payments.map((p) => (
            <p key={p.id} className="text-ink-2">
              Платёж {rub(p.amount)} · {p.status}
              {p.card_mask ? ` · ${p.card_mask}` : ""}
              {p.with_payer ? " · с участием плательщика" : " · по токену"}
              {p.refunded_amount ? ` · возвращено ${rub(p.refunded_amount)}` : ""}
            </p>
          ))}
          {ops.map((o) => (
            <p key={o.id} className="text-ink-2">
              Баланс: {o.reason_label} {rub(o.amount)} · {o.status}
            </p>
          ))}
          {complaints.map((c) => (
            <p key={c.id} className="text-ink-2">
              Жалоба: {c.status}, срок {c.due_date}
              {c.refund_amount ? `, возврат ${rub(c.refund_amount)}` : ""} ·{" "}
              <Link className="text-brand underline" href="/admin/finance?tab=complaints">
                очередь жалоб
              </Link>
            </p>
          ))}
        </Card>
      )}

      <section className="grid gap-2">
        <h2 className="text-lg font-semibold">История</h2>
        <ol className="grid gap-2">
          {history.map((h, i) => (
            <li key={i} className="rounded-xl border border-line bg-surface px-4 py-2 text-[15px]">
              <span className="text-sm text-muted">{dateTime(h.created_at, TZ)} · {ENTITY[h.entity] ?? h.entity}</span>
              <br />
              <span className="font-medium">{h.event}</span> {h.from ?? "—"} → {h.to}
              {h.reason ? ` · ${h.reason}` : ""}
              {h.actor ? ` · ${h.actor.name} ${h.actor.last_name ?? ""}` : " · система"}
            </li>
          ))}
        </ol>
      </section>

      <Modal
        open={dialog !== null}
        onClose={() => setDialog(null)}
        title={dialog === "outcome" ? "Итог сессии по журналу" : "Отмена сессии платформой"}
        footer={
          <Button variant={dialog === "cancel" ? "danger" : "primary"} loading={busy} disabled={reason.trim().length < 3} onClick={submit}>
            Сохранить
          </Button>
        }
      >
        <div className="grid gap-4">
          {dialog === "outcome" ? (
            <Field label="Итог">
              <Select value={outcome} onChange={(e) => setOutcomeValue(e.target.value)}>
                {(Object.keys(OUTCOME_LABELS) as (keyof typeof OUTCOME_LABELS)[]).map((k) => (
                  <option key={k} value={k} disabled={k === s.status}>
                    {OUTCOME_LABELS[k]}
                  </option>
                ))}
              </Select>
            </Field>
          ) : (
            <Alert tone="warning">Сессия получит статус «Отменена системой», оплата полностью зачислится клиенту на баланс, клиент и психолог получат письма.</Alert>
          )}
          <Field label="Причина" hint="Обязательно, попадёт в журнал аудита.">
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          {error && <Alert tone="danger">{error}</Alert>}
        </div>
      </Modal>
    </div>
  );
}
