import { clsx } from "clsx";
import type { ReactNode } from "react";

export type Column<T> = { key: string; title: ReactNode; render: (row: T) => ReactNode; className?: string };

export function Table<T>({ columns, rows, rowKey, empty }: { columns: Column<T>[]; rows: T[]; rowKey: (row: T) => string | number; empty?: ReactNode }) {
  if (rows.length === 0 && empty) return <>{empty}</>;
  return (
    <div className="overflow-x-auto rounded-2xl border border-line bg-surface">
      <table className="w-full min-w-[640px] border-collapse text-left text-[15px]">
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} className={clsx("bg-sunken px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted", c.className)}>
                {c.title}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)} className="border-t border-line align-top">
              {columns.map((c) => (
                <td key={c.key} className={clsx("px-4 py-3", c.className)}>
                  {c.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
