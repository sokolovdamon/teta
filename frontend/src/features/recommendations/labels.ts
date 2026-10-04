import type { RecoLink, RecoStatus, RecoType } from "./types";

export const TYPE_LABELS: Record<RecoType, string> = { task: "Задание", exercise: "Упражнение", material: "Материал" };

/** DM-12 statuses as the psychologist sees them. */
export const STATUS_LABELS: Record<RecoStatus, string> = {
  draft: "Черновик",
  sent: "Отправлена",
  viewed: "Просмотрена",
  done: "Выполнена",
  revoked: "Отозвана",
};

export const STATUS_TONES: Record<RecoStatus, "neutral" | "brand" | "success" | "warning" | "danger"> = {
  draft: "neutral",
  sent: "brand",
  viewed: "warning",
  done: "success",
  revoked: "danger",
};

/** For the client a sent recommendation is simply «Новая». */
export function clientStatusLabel(status: RecoStatus): string {
  return status === "sent" ? "Новая" : STATUS_LABELS[status];
}

/** External links open in a new tab; platform pages (knowledge base materials) stay in the app. */
export function isInternalLink(link: Pick<RecoLink, "url">): boolean {
  return link.url.startsWith("/") && !link.url.startsWith("//");
}

export function linkTitle(link: RecoLink): string {
  if (link.title) return link.title;
  if (isInternalLink(link)) return link.kb_material_id ? "Материал из базы знаний" : link.url;
  try {
    return new URL(link.url).hostname.replace(/^www\./, "");
  } catch {
    return link.url;
  }
}

/** Accepts what the backend accepts: http(s) links or platform paths. */
export function isValidLinkUrl(url: string): boolean {
  return /^(https?:\/\/|\/(?!\/))\S*$/i.test(url.trim());
}

export function fileSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} КБ`;
  return `${(bytes / 1024 / 1024).toFixed(1).replace(".", ",")} МБ`;
}
