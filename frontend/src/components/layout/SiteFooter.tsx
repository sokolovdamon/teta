import Link from "next/link";
import { Logo } from "./Logo";

const columns = [
  {
    title: "Клиентам",
    links: [
      { href: "/podbor", label: "Подбор психолога" },
      { href: "/psychologists", label: "Каталог психологов" },
      { href: "/prices", label: "Цены и правила отмены" },
      { href: "/tests", label: "Психологические тесты" },
      { href: "/certificates", label: "Подарочные сертификаты" },
    ],
  },
  {
    title: "Специалистам",
    links: [
      { href: "/for-psychologists", label: "Стать психологом ТЕТА" },
      { href: "/articles", label: "Статьи" },
      { href: "/events", label: "Вебинары и курсы" },
    ],
  },
  {
    title: "О платформе",
    links: [
      { href: "/about", label: "О нас" },
      { href: "/for-companies", label: "Корпоративным клиентам" },
      { href: "/docs", label: "Документы" },
      { href: "/emergency", label: "Экстренная помощь" },
    ],
  },
];

export function SiteFooter() {
  return (
    <footer className="mt-24 border-t border-line bg-surface">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.2fr_repeat(3,1fr)]">
        <div className="grid content-start gap-3">
          <Logo />
          <p className="max-w-xs text-sm text-muted">
            Портал психологической помощи. Только дипломированные специалисты, ежемесячная супервизия, данные хранятся в России.
          </p>
        </div>
        {columns.map((c) => (
          <div key={c.title} className="grid content-start gap-2">
            <p className="text-sm font-semibold uppercase tracking-wider text-muted">{c.title}</p>
            {c.links.map((l) => (
              <Link key={l.href} href={l.href} className="text-[15px] text-ink-2 hover:text-brand">
                {l.label}
              </Link>
            ))}
          </div>
        ))}
      </div>
      <div className="border-t border-line">
        <p className="mx-auto max-w-7xl px-4 py-5 text-sm text-muted sm:px-6">
          © {new Date().getFullYear()} ТЕТА. Платформа не оказывает экстренную помощь: при угрозе жизни звоните 112.
        </p>
      </div>
    </footer>
  );
}
