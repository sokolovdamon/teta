import Link from "next/link";
import { Logo } from "./Logo";
import { LinkButton } from "@/components/ui";
import { getCurrentUser } from "@/lib/server";
import { cabinetHome } from "@/lib/roles";

const links = [
  { href: "/psychologists", label: "Психологи" },
  { href: "/help", label: "С чем помогаем" },
  { href: "/prices", label: "Цены" },
  { href: "/articles", label: "Статьи" },
  { href: "/for-psychologists", label: "Психологам" },
  { href: "/business", label: "Компаниям" },
];

export async function SiteHeader() {
  const user = await getCurrentUser();
  return (
    <header className="sticky top-0 z-30 border-b border-line bg-ground/90 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:gap-6 sm:px-6">
        <Logo />
        <nav className="hidden flex-1 items-center gap-1 lg:flex">
          {links.map((l) => (
            <Link key={l.href} href={l.href} className="rounded-lg px-3 py-2 text-[15px] text-ink-2 hover:bg-sunken hover:text-ink">
              {l.label}
            </Link>
          ))}
        </nav>
        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          <Link href="/help-now" className="hidden text-sm font-medium text-danger sm:inline">
            Экстренная помощь
          </Link>
          {user ? (
            <LinkButton href={cabinetHome(user)} variant="secondary" size="sm">
              Кабинет
            </LinkButton>
          ) : (
            <LinkButton href="/auth/login" variant="ghost" size="sm">
              Войти
            </LinkButton>
          )}
          <LinkButton href="/podbor" size="sm">
            <span className="sm:hidden">Подбор</span>
            <span className="hidden sm:inline">Подобрать психолога</span>
          </LinkButton>
        </div>
      </div>
    </header>
  );
}
