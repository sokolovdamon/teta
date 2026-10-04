import type { ComplaintStatus } from "./types";

export type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

export const COMPLAINT_STATUS: Record<ComplaintStatus, string> = {
  submitted: "Подана",
  in_review: "На рассмотрении",
  waiting_client: "Нужен ваш ответ",
  rejected: "Отклонена",
  approved: "Удовлетворена",
  refunded: "Возврат зачислен",
  withdrawn: "Отозвана",
};

export const COMPLAINT_TONE: Record<ComplaintStatus, Tone> = {
  submitted: "brand",
  in_review: "warning",
  waiting_client: "danger",
  rejected: "neutral",
  approved: "success",
  refunded: "success",
  withdrawn: "neutral",
};

export const PAYMENT_TONE: Record<string, Tone> = {
  created: "neutral",
  requires_3ds: "warning",
  unknown: "warning",
  succeeded: "success",
  declined: "danger",
  partially_refunded: "brand",
  refunded: "neutral",
};

export const BALANCE_STATUS: Record<string, string> = {
  credited: "Зачислено",
  spend_reserved: "Зарезервировано",
  spent: "Списано с баланса",
  spend_reversed: "Резерв снят",
  withdraw_reserved: "Вывод: заявка принята",
  withdraw_processing: "Вывод в обработке",
  withdrawn: "Выведено на карту",
  withdraw_review: "Вывод требует разбора",
  withdraw_cancelled: "Вывод отменён",
};

export const CHARGE_STATUS: Record<string, string> = {
  scheduled: "Спишется автоматически",
  in_progress: "Идёт списание",
  retry_wait: "Оплата не прошла",
  succeeded: "Списано",
  failed_final: "Не оплачено, запись отменена",
  cancelled: "Отменено",
};

/** Signed amount of a balance operation for the ledger: credits are positive, spends and withdrawals negative. */
export function balanceSign(type: "credit" | "spend" | "withdraw", status: string): 1 | -1 | 0 {
  if (type === "credit") return 1;
  if (["spend_reversed", "withdraw_cancelled"].includes(status)) return 0;
  return -1;
}

/** SLA badge of a complaint: overdue, less than 3 working days left, or the number of days left. */
export function slaBadge(sla: "soon" | "overdue" | null, daysLeft: number): { tone: Tone; text: string } {
  if (sla === "overdue") return { tone: "danger", text: `Просрочена на ${Math.abs(daysLeft)} раб. дн.` };
  if (sla === "soon") return { tone: "warning", text: daysLeft === 0 ? "Срок сегодня" : `Осталось ${daysLeft} раб. дн.` };
  return { tone: "neutral", text: `Осталось ${daysLeft} раб. дн.` };
}

/** Digits of a card number grouped by four: "4111 1111 1111 1111". */
export function formatCardNumber(raw: string): string {
  return raw
    .replace(/\D/g, "")
    .slice(0, 19)
    .replace(/(\d{4})(?=\d)/g, "$1 ");
}

/** Normalised gift certificate code as typed: upper case, dashes every four characters after "TETA". */
export function normalizeCertificateCode(raw: string): string {
  return raw.toUpperCase().replace(/[^A-Z0-9-]/g, "").slice(0, 24);
}
