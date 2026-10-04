import type { ReactNode } from "react";
import { Logo } from "./Logo";
import { CabinetNav } from "./CabinetNav";
import { LogoutButton } from "./LogoutButton";
import type { NavGroup } from "@/config/nav";
import type { User } from "@/lib/types";
import { can } from "@/lib/types";

type Props = { user: User; nav: NavGroup[]; title: string; children: ReactNode };

/** Two-column cabinet layout shared by client, psychologist, admin and HR contours. */
export function CabinetShell({ user, nav, title, children }: Props) {
  const visible = nav
    .map((g) => ({
      ...g,
      items: g.items.filter((i) => (!i.permission || can(user, i.permission)) && (!i.roles || i.roles.some((r) => user.roles.includes(r)))),
    }))
    .filter((g) => g.items.length > 0);

  return (
    <div className="flex min-h-screen flex-col lg:flex-row">
      <aside className="border-b border-line bg-surface lg:sticky lg:top-0 lg:h-screen lg:w-72 lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r">
        <div className="flex items-center justify-between gap-3 px-5 py-4">
          <Logo height={32} />
          <span className="text-xs font-semibold uppercase tracking-wider text-muted">{title}</span>
        </div>
        <CabinetNav groups={visible} />
        <div className="hidden border-t border-line px-5 py-4 lg:block">
          <p className="truncate text-sm font-medium">{[user.name, user.last_name].filter(Boolean).join(" ")}</p>
          <p className="truncate text-xs text-muted">{user.email}</p>
          <LogoutButton className="mt-3" />
        </div>
      </aside>
      <main className="min-w-0 flex-1 px-4 py-6 sm:px-8 sm:py-8">{children}</main>
    </div>
  );
}
