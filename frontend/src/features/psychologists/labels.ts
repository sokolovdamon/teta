import type { ActivityStatus, DocumentKind, DocumentStatus, QualificationStatus, VideoStatus, WorkStatus } from "./types";

type Tone = "neutral" | "brand" | "success" | "warning" | "danger";

/** ST-08: «На модерации», «Подтверждён», «Отклонён» (PRO-01). */
export const QUALIFICATION: Record<QualificationStatus, { label: string; tone: Tone }> = {
  draft: { label: "Черновик", tone: "neutral" },
  in_review: { label: "На модерации", tone: "warning" },
  approved: { label: "Подтверждён", tone: "success" },
  rejected: { label: "Отклонён", tone: "danger" },
};

/** ST-09 */
export const ACTIVITY: Record<NonNullable<ActivityStatus> | "none", { label: string; tone: Tone }> = {
  none: { label: "—", tone: "neutral" },
  grace: { label: "Льготный период", tone: "brand" },
  active_not_met: { label: "Активен, супервизии в этом месяце ещё нет", tone: "warning" },
  active_met: { label: "Активен, супервизия пройдена", tone: "success" },
  inactive: { label: "Неактивен: нет супервизии", tone: "danger" },
};

/** BR-PSY-06 */
export const WORK: Record<WorkStatus, { label: string; tone: Tone }> = {
  active: { label: "Принимает новых клиентов", tone: "success" },
  paused: { label: "Пауза", tone: "warning" },
  blocked: { label: "Заблокирован", tone: "danger" },
};

export const VIDEO: Record<VideoStatus, { label: string; tone: Tone }> = {
  none: { label: "Нет видеовизитки", tone: "neutral" },
  pending: { label: "На модерации", tone: "warning" },
  approved: { label: "Опубликована", tone: "success" },
  rejected: { label: "Отклонена", tone: "danger" },
};

export const DOCUMENT_STATUS: Record<DocumentStatus, { label: string; tone: Tone }> = {
  pending: { label: "На проверке", tone: "warning" },
  approved: { label: "Подтверждён", tone: "success" },
  rejected: { label: "Отклонён", tone: "danger" },
};

export const DOCUMENT_KIND: Record<DocumentKind, string> = {
  diploma: "Диплом о психологическом образовании",
  retraining: "Диплом о профессиональной переподготовке",
  certificate: "Сертификат, удостоверение о повышении квалификации",
  other: "Другой документ",
};

export const HISTORY_EVENTS: Record<string, string> = {
  draft: "Профиль создан",
  in_review: "Заявка отправлена на модерацию",
  approved: "Квалификация подтверждена",
  rejected: "Квалификация не подтверждена",
};

export const TIMEZONES = [
  "Europe/Kaliningrad",
  "Europe/Moscow",
  "Europe/Samara",
  "Asia/Yekaterinburg",
  "Asia/Omsk",
  "Asia/Novosibirsk",
  "Asia/Krasnoyarsk",
  "Asia/Irkutsk",
  "Asia/Yakutsk",
  "Asia/Vladivostok",
  "Asia/Magadan",
  "Asia/Kamchatka",
  "Europe/Minsk",
  "Asia/Almaty",
  "Asia/Tbilisi",
  "Asia/Yerevan",
  "Europe/Istanbul",
  "Europe/Berlin",
  "Asia/Dubai",
];

export const WEEKDAYS = ["Понедельник", "Вторник", "Среда", "Четверг", "Пятница", "Суббота", "Воскресенье"];
