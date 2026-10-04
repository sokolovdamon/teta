import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = { title: "Технические работы", robots: { index: false, follow: false } };

/** SITE-18: maintenance page (nginx can route here while the platform is being updated). */
export default function MaintenancePage() {
  return (
    <main className="mx-auto grid max-w-2xl justify-items-center gap-4 px-4 py-24 text-center sm:px-6">
      <p className="text-sm font-semibold uppercase tracking-wider text-brand">Технические работы</p>
      <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Скоро вернёмся</h1>
      <p className="text-lg text-ink-2">
        Мы обновляем платформу. Это займёт немного времени. Назначенные сессии не отменяются — если работы затронут время вашей встречи, мы напишем на email.
      </p>
      <p className="text-ink-2">
        Если вам нужна помощь прямо сейчас, откройте страницу{" "}
        <Link href="/help-now" className="font-medium text-brand hover:underline">
          «Экстренная помощь»
        </Link>{" "}
        или звоните 112.
      </p>
    </main>
  );
}
