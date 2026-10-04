"use client";

import { Bell } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { echo } from "@/lib/echo";

/** Unread counter of the notification centre (CL-14); live via Reverb, with polling as a fallback. */
export function NotificationBell({ userId, href }: { userId: string; href: string }) {
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    let alive = true;
    const load = () =>
      api<{ unread: number }>("/notifications/unread-count")
        .then((r) => alive && setUnread(r.unread))
        .catch(() => null);
    load();
    const timer = window.setInterval(load, 60_000);
    const channel = echo()?.private(`users.${userId}`);
    channel?.listen(".notification.created", () => setUnread((n) => n + 1));
    return () => {
      alive = false;
      window.clearInterval(timer);
      echo()?.leave(`users.${userId}`);
    };
  }, [userId]);

  return (
    <Link href={href} className="relative inline-flex size-10 items-center justify-center rounded-xl text-ink-2 hover:bg-sunken" aria-label={`Уведомления: ${unread} новых`}>
      <Bell className="size-5" />
      {unread > 0 && (
        <span className="absolute right-1 top-1 min-w-5 rounded-full bg-brand px-1 text-center text-[11px] font-semibold leading-5 text-on-brand">
          {unread > 99 ? "99+" : unread}
        </span>
      )}
    </Link>
  );
}
