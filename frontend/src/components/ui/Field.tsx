import { clsx } from "clsx";
import type { ComponentProps, ReactNode } from "react";

const control =
  "w-full rounded-xl border border-line-strong bg-surface px-4 text-ink placeholder:text-muted focus:border-brand focus:outline-none aria-[invalid=true]:border-danger";

type FieldProps = { label?: ReactNode; hint?: ReactNode; error?: string | null; children: ReactNode; className?: string };

export function Field({ label, hint, error, children, className }: FieldProps) {
  return (
    <label className={clsx("grid gap-1.5", className)}>
      {label && <span className="text-sm font-medium text-ink-2">{label}</span>}
      {children}
      {error ? <span className="text-sm text-danger">{error}</span> : hint ? <span className="text-sm text-muted">{hint}</span> : null}
    </label>
  );
}

export function Input({ className, ...rest }: ComponentProps<"input">) {
  return <input className={clsx(control, "h-11", className)} {...rest} />;
}

export function Textarea({ className, ...rest }: ComponentProps<"textarea">) {
  return <textarea className={clsx(control, "min-h-28 py-3", className)} {...rest} />;
}

export function Select({ className, children, ...rest }: ComponentProps<"select">) {
  return (
    <select className={clsx(control, "h-11 pr-8", className)} {...rest}>
      {children}
    </select>
  );
}

type CheckboxProps = Omit<ComponentProps<"input">, "type"> & { label: ReactNode };

export function Checkbox({ label, className, ...rest }: CheckboxProps) {
  return (
    <label className={clsx("flex items-start gap-3 text-[15px] text-ink-2", className)}>
      <input type="checkbox" className="mt-1 size-4 shrink-0 accent-brand" {...rest} />
      <span>{label}</span>
    </label>
  );
}
