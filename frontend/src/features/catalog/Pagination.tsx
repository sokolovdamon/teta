import { clsx } from "clsx";
import Link from "next/link";

/** Numbered pagination with crawlable links (?page=N). */
export function Pagination({ page, lastPage, href }: { page: number; lastPage: number; href: (page: number) => string }) {
  if (lastPage <= 1) return null;
  const pages = new Set<number>([1, lastPage, page - 1, page, page + 1].filter((p) => p >= 1 && p <= lastPage));
  const list = [...pages].sort((a, b) => a - b);

  return (
    <nav aria-label="Страницы" className="flex flex-wrap items-center justify-center gap-1.5">
      {page > 1 && (
        <Link href={href(page - 1)} className="rounded-xl px-3 py-2 text-[15px] text-brand hover:bg-brand-soft" rel="prev">
          ← Назад
        </Link>
      )}
      {list.map((p, i) => (
        <span key={p} className="flex items-center gap-1.5">
          {i > 0 && p - list[i - 1] > 1 && <span className="px-1 text-muted">…</span>}
          <Link
            href={href(p)}
            aria-current={p === page ? "page" : undefined}
            className={clsx(
              "min-w-10 rounded-xl px-3 py-2 text-center text-[15px]",
              p === page ? "bg-brand text-on-brand" : "text-ink-2 hover:bg-sunken",
            )}
          >
            {p}
          </Link>
        </span>
      ))}
      {page < lastPage && (
        <Link href={href(page + 1)} className="rounded-xl px-3 py-2 text-[15px] text-brand hover:bg-brand-soft" rel="next">
          Дальше →
        </Link>
      )}
    </nav>
  );
}
