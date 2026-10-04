import { date } from "@/lib/format";
import type { ClientCardData, DiaryAccess, RelationStatus } from "./types";

export const RELATION_LABELS: Record<RelationStatus, string> = {
  active: "Есть запись",
  no_upcoming: "Нет записи",
  finished: "Работа завершена",
  changed: "Сменил(а) психолога",
};

export const RELATION_TONES: Record<RelationStatus, "brand" | "neutral" | "success" | "warning"> = {
  active: "brand",
  no_upcoming: "neutral",
  finished: "success",
  changed: "warning",
};

/** ST-01 statuses in the psychologist's words. */
export const SESSION_STATUS_LABELS: Record<string, string> = {
  booked: "Забронирована",
  paid: "Оплачена",
  in_progress: "Идёт",
  held: "Проведена",
  client_no_show: "Клиент не пришёл",
  psy_no_show: "Неявка психолога",
  tech_issue: "Техническая проблема",
  cancelled_by_client: "Отменена клиентом",
  cancelled_by_psy: "Отменена вами",
  cancelled_by_system: "Отменена системой",
};

export const TABS = [
  { id: "sessions", label: "Сессии" },
  { id: "dynamics", label: "Динамика" },
  { id: "recommendations", label: "Рекомендации" },
  { id: "notes", label: "Заметки" },
] as const;

export type TabId = (typeof TABS)[number]["id"];

export function parseTab(value: string | string[] | undefined): TabId {
  const v = Array.isArray(value) ? value[0] : value;
  return TABS.some((t) => t.id === v) ? (v as TabId) : "sessions";
}

/**
 * DM-08 explained to the psychologist: what part of the diary is visible and why.
 * Returns null when the dynamics are fully visible.
 */
export function diaryAccessText(access: DiaryAccess, status: RelationStatus, tz: string): string | null {
  if (!access.available) {
    return "Динамика дневника доступна психологу, к которому клиент записан или у которого проходил сессии.";
  }
  if (!access.restricted || !access.until) return null;
  const until = date(access.until, tz);
  return status === "changed"
    ? `Клиент сменил психолога ${until}: вы видите динамику только до этой даты.`
    : `Работа отмечена завершённой ${until}: вы видите динамику только до этой даты. Если клиент снова запишется к вам, ограничение снимется.`;
}

/** Why the «Работа завершена» button is unavailable, or null when it can be pressed. */
export function finishBlockReason(card: Pick<ClientCardData, "status" | "upcoming_count" | "can_finish">): string | null {
  if (card.can_finish) return null;
  if (card.status === "finished") return "Работа уже отмечена завершённой.";
  if (card.status === "changed") return "Клиент сменил психолога.";
  if (card.upcoming_count > 0) return "У клиента есть назначенные сессии — отметить завершение можно после них.";
  return "Отметка сейчас недоступна.";
}
