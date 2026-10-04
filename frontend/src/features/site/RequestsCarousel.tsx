"use client";

import { clsx } from "clsx";
import Link from "next/link";
import { useRef, useState } from "react";
import type { Dictionaries } from "@/features/catalog/types";

type Group = Dictionaries["request_groups"][number];

/** SITE-01, DEC-11: carousel of all requests with switching between the groups; each card leads to its landing. */
export function RequestsCarousel({ groups }: { groups: Group[] }) {
  const [active, setActive] = useState(0);
  const track = useRef<HTMLUListElement>(null);
  const group = groups[active];
  if (!group) return null;

  const scroll = (dir: 1 | -1) => track.current?.scrollBy?.({ left: dir * Math.max(240, track.current.clientWidth * 0.8), behavior: "smooth" });

  return (
    <div className="grid gap-5">
      <div role="tablist" aria-label="Группы запросов" className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
        {groups.map((g, i) => (
          <button
            key={g.slug}
            type="button"
            role="tab"
            id={`requests-tab-${g.slug}`}
            aria-selected={i === active}
            aria-controls="requests-panel"
            onClick={() => {
              setActive(i);
              if (track.current) track.current.scrollLeft = 0;
            }}
            className={clsx(
              "shrink-0 rounded-full border px-4 py-2 text-[15px] transition-colors",
              i === active ? "border-brand bg-brand text-on-brand" : "border-line bg-surface text-ink-2 hover:border-brand-tint",
            )}
          >
            {g.title} <span className={i === active ? "opacity-75" : "text-muted"}>{g.requests.length}</span>
          </button>
        ))}
      </div>

      <div id="requests-panel" role="tabpanel" aria-labelledby={`requests-tab-${group.slug}`} className="relative">
        <ul ref={track} className="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth px-4 pb-3 sm:mx-0 sm:px-0">
          {group.requests.map((r) => (
            <li key={r.id} className="snap-start">
              <Link
                href={r.path}
                className="flex h-32 w-56 flex-col justify-between rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-brand hover:bg-brand-soft/40"
              >
                <span className="text-[15px] font-medium leading-snug">{r.title}</span>
                <span className="flex items-center justify-between text-sm text-muted">
                  {r.format === "pair" ? "Для пары" : "Индивидуально"}
                  {r.age_label && <span className="rounded-full bg-sunken px-2 py-0.5 text-xs">{r.age_label}</span>}
                  <span aria-hidden className="text-brand">
                    →
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
        <div className="mt-1 hidden justify-end gap-2 sm:flex">
          <button type="button" onClick={() => scroll(-1)} className="rounded-full border border-line bg-surface px-3 py-1.5 text-sm hover:border-brand" aria-label="Прокрутить назад">
            ←
          </button>
          <button type="button" onClick={() => scroll(1)} className="rounded-full border border-line bg-surface px-3 py-1.5 text-sm hover:border-brand" aria-label="Прокрутить вперёд">
            →
          </button>
        </div>
      </div>
    </div>
  );
}
