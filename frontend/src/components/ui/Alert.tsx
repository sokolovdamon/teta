import { clsx } from "clsx";
import type { ReactNode } from "react";

type Tone = "info" | "success" | "warning" | "danger";
const tones: Record<Tone, string> = {
  info: "border-brand-tint bg-brand-soft text-ink",
  success: "border-success bg-success-soft text-ink",
  warning: "border-warning bg-warning-soft text-ink",
  danger: "border-danger bg-danger-soft text-ink",
};

export function Alert({ tone = "info", title, children, className }: { tone?: Tone; title?: ReactNode; children?: ReactNode; className?: string }) {
  return (
    <div role={tone === "danger" ? "alert" : "status"} className={clsx("rounded-xl border-l-4 px-4 py-3", tones[tone], className)}>
      {title && <p className="font-semibold">{title}</p>}
      {children && <div className="text-[15px] text-ink-2">{children}</div>}
    </div>
  );
}
