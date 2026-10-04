import { clsx } from "clsx";
import type { ComponentProps, ReactNode } from "react";

export function Card({ className, ...rest }: ComponentProps<"div">) {
  return <div className={clsx("rounded-2xl border border-line bg-surface p-5 sm:p-6", className)} {...rest} />;
}

type SectionTitleProps = { title: ReactNode; description?: ReactNode; action?: ReactNode; className?: string };

export function PageHeader({ title, description, action, className }: SectionTitleProps) {
  return (
    <div className={clsx("mb-6 flex flex-wrap items-end justify-between gap-4", className)}>
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
        {description && <p className="max-w-2xl text-ink-2">{description}</p>}
      </div>
      {action}
    </div>
  );
}

export function EmptyState({ title, description, action }: { title: ReactNode; description?: ReactNode; action?: ReactNode }) {
  return (
    <div className="grid justify-items-center gap-2 rounded-2xl border border-dashed border-line-strong px-6 py-12 text-center">
      <p className="text-lg font-medium">{title}</p>
      {description && <p className="max-w-md text-muted">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
