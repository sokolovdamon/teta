import Link from "next/link";
import { LinkButton } from "@/components/ui";

/** SITE-18: body of the 404 page (used by the root boundary and by the site segment). */
export function NotFoundContent() {
  return (
    <main className="mx-auto grid w-full max-w-2xl flex-1 justify-items-center gap-4 px-4 py-24 text-center sm:px-6">
      <p className="text-6xl font-bold tracking-tight text-brand-tint num">404</p>
      <h1 className="text-3xl font-bold tracking-tight">Такой страницы нет</h1>
      <p className="text-lg text-ink-2">Возможно, ссылка устарела или в адресе опечатка. Давайте начнём с главного:</p>
      <div className="mt-2 flex flex-wrap justify-center gap-3">
        <LinkButton href="/">На главную</LinkButton>
        <LinkButton href="/psychologists" variant="secondary">
          Каталог психологов
        </LinkButton>
        <LinkButton href="/podbor" variant="secondary">
          Подобрать психолога
        </LinkButton>
      </div>
      <p className="mt-4 text-sm text-muted">
        Нужна помощь прямо сейчас?{" "}
        <Link href="/help-now" className="font-medium text-brand hover:underline">
          Экстренная помощь
        </Link>
      </p>
    </main>
  );
}
