import { clsx } from "clsx";
import type { ReactNode } from "react";

type Tone = "neutral" | "brand" | "success" | "warning" | "danger";
const tones: Record<Tone, string> = {
  neutral: "bg-sunken text-ink-2",
  brand: "bg-brand-soft text-brand",
  success: "bg-success-soft text-success",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
};

export function Badge({ tone = "neutral", children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return (
    <span className={clsx("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", tones[tone], className)}>
      {children}
    </span>
  );
}

export function Chip({ children, active, onClick }: { children: ReactNode; active?: boolean; onClick?: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={clsx(
        "rounded-full border px-3.5 py-1.5 text-sm transition-colors",
        active ? "border-brand bg-brand text-on-brand" : "border-line bg-surface text-ink hover:border-brand-tint",
      )}
    >
      {children}
    </button>
  );
}
