"use client";

import Link from "next/link";
import { useEffect } from "react";
import { Button } from "@/components/ui";

/** SITE-18: 500 — an unexpected error in a page; retry() re-fetches and re-renders the segment. */
export default function ErrorPage({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <main className="mx-auto grid w-full max-w-2xl flex-1 justify-items-center gap-4 px-4 py-24 text-center sm:px-6">
      <p className="text-sm font-semibold uppercase tracking-wider text-brand">Что-то пошло не так</p>
      <h1 className="text-3xl font-bold tracking-tight">Не получилось открыть страницу</h1>
      <p className="text-lg text-ink-2">
        Мы уже знаем о проблеме. Попробуйте ещё раз через минуту — обычно это помогает. Ваши записи и данные в безопасности.
      </p>
      <div className="mt-2 flex flex-wrap justify-center gap-3">
        <Button onClick={() => retry()}>Попробовать ещё раз</Button>
        <Link
          href="/"
          className="inline-flex h-11 items-center justify-center rounded-xl border border-line-strong bg-surface px-5 text-[15px] font-medium text-ink hover:bg-sunken"
        >
          На главную
        </Link>
      </div>
      {error.digest && <p className="text-xs text-muted">Код ошибки: {error.digest}</p>}
      <p className="mt-4 text-sm text-muted">
        Если вам нужна помощь прямо сейчас —{" "}
        <Link href="/help-now" className="font-medium text-brand hover:underline">
          экстренная помощь
        </Link>
        .
      </p>
    </main>
  );
}
