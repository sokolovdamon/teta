"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui";

const KEY = "teta_cookies_ack";

/** SITE-00: cookie notice. Consent is not a condition of the service (ЗоЗПП ст. 16). */
export function CookieBanner() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    try {
      // Reading browser storage is only possible after mount; the banner is hidden during SSR.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setVisible(!window.localStorage.getItem(KEY));
    } catch {
      setVisible(true);
    }
  }, []);

  if (!visible) return null;
  return (
    <div className="fixed inset-x-3 bottom-3 z-50 mx-auto flex max-w-3xl flex-wrap items-center gap-3 rounded-2xl border border-line bg-surface p-4 shadow-lg sm:inset-x-6">
      <p className="flex-1 text-sm text-ink-2">
        Мы используем cookies, чтобы сайт работал корректно, и Яндекс.Метрику для статистики посещений. Подробнее — в{" "}
        <Link href="/legal/cookies" className="text-brand underline">
          политике cookies
        </Link>
        .
      </p>
      <Button
        size="sm"
        onClick={() => {
          try {
            window.localStorage.setItem(KEY, "1");
          } catch {}
          setVisible(false);
        }}
      >
        Понятно
      </Button>
    </div>
  );
}
