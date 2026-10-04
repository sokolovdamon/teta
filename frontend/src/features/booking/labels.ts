import { rub } from "@/lib/format";
import { sessionDateTime } from "./time";
import type { ClientSession, SessionStatus } from "./types";

export type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

/** Statuses as the client sees them (CL-03): plain Russian, from the client's point of view. */
export const CLIENT_STATUS: Record<SessionStatus, string> = {
  booked: "Записаны",
  paid: "Оплачена",
  in_progress: "Идёт",
  held: "Проведена",
  client_no_show: "Неявка",
  psy_no_show: "Психолог не подключился",
  tech_issue: "Техническая проблема",
  cancelled_by_client: "Отменена вами",
  cancelled_by_psy: "Отменена психологом",
  cancelled_by_system: "Отменена платформой",
};

/** Statuses for the psychologist and administrators (ST-01 names). */
export const STATUS: Record<SessionStatus, string> = {
  booked: "Забронирована",
  paid: "Оплачена",
  in_progress: "Идёт",
  held: "Проведена",
  client_no_show: "Неявка клиента",
  psy_no_show: "Неявка психолога",
  tech_issue: "Техническая проблема",
  cancelled_by_client: "Отменена клиентом",
  cancelled_by_psy: "Отменена психологом",
  cancelled_by_system: "Отменена системой",
};

export const STATUS_TONE: Record<SessionStatus, Tone> = {
  booked: "brand",
  paid: "success",
  in_progress: "warning",
  held: "success",
  client_no_show: "neutral",
  psy_no_show: "danger",
  tech_issue: "warning",
  cancelled_by_client: "neutral",
  cancelled_by_psy: "danger",
  cancelled_by_system: "neutral",
};

export const OUTCOME_LABELS: Record<"held" | "client_no_show" | "tech_issue" | "psy_no_show", string> = {
  held: "Проведена",
  client_no_show: "Клиент не пришёл",
  tech_issue: "Техническая проблема",
  psy_no_show: "Неявка психолога",
};

/** Rule text shown before a cancel (DEC-23): free until the charge, full retention after it. */
export function cancelRuleText(s: Pick<ClientSession, "is_charged" | "free_cancel_until" | "is_corporate" | "amount_charged" | "amount_due">, tz: string): string {
  if (s.is_corporate) {
    return s.is_charged
      ? "Сессия уже засчитана в лимит корпоративной программы: при отмене лимит не восстановится."
      : "Отмена бесплатна: лимит корпоративной программы сохранится.";
  }
  if (!s.is_charged) {
    const until = s.free_cancel_until ? sessionDateTime(s.free_cancel_until, tz) : null;
    return until
      ? `Отмена бесплатна до ${until} — в этот момент спишется оплата. Сейчас оплата не списана, ничего не удержим.`
      : "Оплата ещё не списана — отмена бесплатна.";
  }
  return `Оплата ${rub(s.amount_charged ?? s.amount_due ?? 0)} уже списана: при отмене она не возвращается. Вместо отмены можно перенести сессию на время не раньше чем через 12 часов.`;
}

/** Rule text in the reschedule dialog (DEC-56). */
export function rescheduleRuleText(late: boolean, left: number | null): string {
  if (!late) return "До списания оплаты перенос бесплатный и не ограничен: можно выбрать любое свободное время этого психолога.";
  const limit = left === null ? "" : left > 0 ? ` Осталось переносов: ${left}.` : " Лимит переносов исчерпан.";
  return `Оплата уже списана: перенести можно на время не раньше чем через 12 часов от текущего момента, оплата перейдёт на новое время.${limit}`;
}

/** Why the client has to choose between a refund and a free reschedule (BR-CANC-06). */
export function choiceReason(status: SessionStatus): string {
  switch (status) {
    case "psy_no_show":
      return "Психолог не подключился к сессии. Приносим извинения.";
    case "tech_issue":
      return "Сессия не состоялась из-за технической проблемы.";
    default:
      return "Психолог отменил сессию. Приносим извинения.";
  }
}

/** Payment line of a session card in CL-03. */
export function paymentLine(s: ClientSession, tz: string): string | null {
  if (s.role === "partner") return "Оплату вносит пригласивший";
  if (s.is_corporate) return "Оплачивает компания";
  if (s.status === "booked") {
    if ((s.amount_due ?? 0) === 0) return "Бесплатно по промокоду";
    if (s.charge?.status === "retry_wait") return `Оплата не прошла — оплатите до ${s.charge.deadline_at ? sessionDateTime(s.charge.deadline_at, tz) : "начала"}`;
    return s.free_cancel_until ? `${rub(s.amount_due ?? 0)} спишется ${sessionDateTime(s.free_cancel_until, tz)}` : null;
  }
  if ((s.balance_refunded ?? 0) > 0 && s.client_choice !== "reschedule") return `${rub(s.balance_refunded ?? 0)} возвращено на баланс`;
  if (s.paid_at) return `Оплачено ${rub(s.amount_charged ?? 0)}${(s.discount ?? 0) > 0 ? `, скидка ${rub(s.discount ?? 0)}` : ""}`;
  return null;
}

export function isUpcoming(s: { status: SessionStatus; client_choice: string | null }): boolean {
  return ["booked", "paid", "in_progress"].includes(s.status) || s.client_choice === "pending";
}
