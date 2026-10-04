"use client";

import { useEffect, useRef, type ReactNode } from "react";

type ModalProps = { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; footer?: ReactNode };

export function Modal({ open, onClose, title, children, footer }: ModalProps) {
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={ref}
      onClose={onClose}
      className="m-auto w-[min(560px,calc(100vw-32px))] rounded-2xl border border-line bg-surface p-0 text-ink backdrop:bg-black/40"
    >
      <div className="grid gap-4 p-6">
        <div className="flex items-start justify-between gap-4">
          <h2 className="text-xl font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="text-muted hover:text-ink" aria-label="Закрыть">
            ✕
          </button>
        </div>
        <div>{children}</div>
        {footer && <div className="flex flex-wrap justify-end gap-3">{footer}</div>}
      </div>
    </dialog>
  );
}
