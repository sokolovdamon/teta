"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Button, Card, LinkButton } from "@/components/ui";
import { clockSkew, roomState, startsIn } from "./room";
import type { ClientHome } from "./types";

type Props = { session: NonNullable<ClientHome["next_session"]>; roomWindow: ClientHome["room_window"]; serverTime: string; timezone: string };

function longDateTime(iso: string, tz: string): string {
  return new Intl.DateTimeFormat("ru-RU", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
}

/** CL-02 S3/S4: the next session with a live «Войти в TetaMeet» inside the room window. */
export function NextSessionCard({ session, roomWindow, serverTime, timezone }: Props) {
  // The first render uses the server time so that SSR and hydration agree; then the browser clock takes over.
  const [now, setNow] = useState(() => Date.parse(serverTime));

  useEffect(() => {
    const skew = clockSkew(serverTime, Date.now());
    const tick = () => setNow(Date.now() + skew);
    const first = window.setTimeout(tick, 0);
    const timer = window.setInterval(tick, 15_000);
    return () => {
      window.clearTimeout(first);
      window.clearInterval(timer);
    };
  }, [serverTime]);

  const state = roomState(now, session.room.opens_at, session.room.closes_at);
  const p = session.psychologist;

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">Ближайшая сессия</h2>
        <Badge tone={session.is_paid ? "success" : "neutral"}>{session.is_paid ? "Оплачена" : "Ожидает оплаты"}</Badge>
      </div>
      <div className="grid gap-1">
        <p className="text-xl font-semibold first-letter:uppercase sm:text-2xl">{longDateTime(session.starts_at, timezone)}</p>
        <p className="text-ink-2">
          {state === "open" ? "Сессия начинается — можно входить" : startsIn(now, session.starts_at)} · {session.format === "pair" ? "парная" : "индивидуальная"},{" "}
          {session.duration_min} мин
        </p>
        {p && (
          <p className="text-ink-2">
            Психолог:{" "}
            <Link href={p.profile_url} className="font-medium text-brand">
              {p.name}
            </Link>
          </p>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        {state === "open" ? (
          <LinkButton href={session.room.url} size="lg">
            Войти в TetaMeet
          </LinkButton>
        ) : state === "before" ? (
          <>
            <Button size="lg" disabled aria-describedby="room-hint">
              Войти в TetaMeet
            </Button>
            <span id="room-hint" className="text-sm text-muted">
              Вход откроется за {roomWindow.open_before_min} мин до начала
            </span>
          </>
        ) : null}
        <LinkButton href="/client/sessions" variant="ghost">
          Все сессии
        </LinkButton>
      </div>
    </Card>
  );
}
