"use client";

import { clsx } from "clsx";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { NavGroup } from "@/config/nav";

export function CabinetNav({ groups }: { groups: NavGroup[] }) {
  const pathname = usePathname();
  const all = groups.flatMap((g) => g.items);
  // The deepest matching href is active, so "/pro" doesn't stay highlighted on "/pro/clients".
  const active = all
    .filter((i) => pathname === i.href || pathname.startsWith(i.href + "/"))
    .sort((a, b) => b.href.length - a.href.length)[0]?.href;

  return (
    <nav className="flex gap-1 overflow-x-auto px-3 pb-3 lg:grid lg:gap-4 lg:overflow-visible lg:pb-6">
      {groups.map((g, gi) => (
        <div key={gi} className="flex gap-1 lg:grid lg:gap-0.5">
          {g.title && <p className="hidden px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted lg:block">{g.title}</p>}
          {g.items.map((i) => (
            <Link
              key={i.id}
              href={i.href}
              className={clsx(
                "whitespace-nowrap rounded-lg px-3 py-2 text-[15px]",
                active === i.href ? "bg-brand-soft font-medium text-brand" : "text-ink-2 hover:bg-sunken hover:text-ink",
              )}
            >
              {i.label}
            </Link>
          ))}
        </div>
      ))}
    </nav>
  );
}
