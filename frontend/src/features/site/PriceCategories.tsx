import Link from "next/link";
import type { Dictionaries } from "@/features/catalog/types";
import { rub } from "@/lib/format";

/** DEC-19, DEC-55: the psychologist sets the price; categories follow the price of an individual session. */
export function PriceCategories({ categories }: { categories: Dictionaries["price_categories"] }) {
  if (categories.length === 0) return null;
  return (
    <ul className="grid gap-4 md:grid-cols-3">
      {categories.map((c) => (
        <li key={c.code} className="grid content-start gap-2 rounded-2xl bg-surface p-6 ring-1 ring-line">
          <p className="text-2xl font-semibold tracking-tight num">{c.title}</p>
          <p className="text-sm text-muted">{range(c.min_price, c.max_price)}</p>
          <Link href={`/psychologists?price=${c.code}`} className="mt-2 text-[15px] font-medium text-brand hover:underline">
            Психологи в этой категории →
          </Link>
        </li>
      ))}
    </ul>
  );
}

function range(min: number, max: number | null): string {
  if (!min && max !== null) return `Индивидуальная сессия дешевле ${rub(max + 1)}`;
  if (max === null) return `Индивидуальная сессия от ${rub(min)}`;
  return `Индивидуальная сессия от ${rub(min)} до ${rub(Math.floor(max / 100) * 100)}`;
}
