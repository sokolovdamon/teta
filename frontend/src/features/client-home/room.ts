import { plural } from "@/lib/format";

/**
 * TetaMeet entry window (P-ROOM-OPEN before the start … P-ROOM-CLOSE after the end). The backend returns the
 * exact moments; the browser only compares them with its clock corrected by the server time skew.
 */
export type RoomState = "before" | "open" | "closed";

export function roomState(nowMs: number, opensAt: string, closesAt: string): RoomState {
  if (nowMs < Date.parse(opensAt)) return "before";
  if (nowMs <= Date.parse(closesAt)) return "open";
  return "closed";
}

/** Milliseconds to add to Date.now() to get the server's time. */
export function clockSkew(serverTimeIso: string, clientNowMs: number): number {
  const server = Date.parse(serverTimeIso);
  return Number.isNaN(server) ? 0 : server - clientNowMs;
}

/** When the next state change happens (to re-render exactly then), or null when nothing will change. */
export function nextChangeIn(nowMs: number, opensAt: string, closesAt: string): number | null {
  const open = Date.parse(opensAt);
  const close = Date.parse(closesAt);
  if (nowMs < open) return open - nowMs;
  if (nowMs <= close) return close - nowMs + 1;
  return null;
}

/** «через 2 дня», «через 3 ч 15 мин», «через 5 мин», «сейчас». */
export function startsIn(nowMs: number, startsAt: string): string {
  const diff = Date.parse(startsAt) - nowMs;
  if (diff <= 0) return "сейчас";
  const minutes = Math.ceil(diff / 60_000);
  if (minutes < 60) return `через ${minutes} мин`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    const rest = minutes % 60;
    return rest ? `через ${hours} ч ${rest} мин` : `через ${hours} ч`;
  }
  const days = Math.floor(hours / 24);
  return `через ${days} ${plural(days, ["день", "дня", "дней"])}`;
}
