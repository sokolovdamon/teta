import Link from "next/link";
import { Logo } from "@/components/layout/Logo";

/** Payment pages: the emulator checkout (in production — the provider's form) and the return page. */
export default function PayLayout({ children }: LayoutProps<"/pay">) {
  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex h-16 max-w-3xl items-center justify-between px-4">
          <Link href="/" aria-label="На главную">
            <Logo height={30} />
          </Link>
          <span className="text-sm text-muted">Безопасная оплата</span>
        </div>
      </header>
      <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:py-12">{children}</main>
    </div>
  );
}
