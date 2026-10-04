"use client";

import { useEffect, useState } from "react";
import { Alert, Button, Modal } from "@/components/ui";
import { rescheduleSlots } from "./api";
import { rescheduleRuleText } from "./labels";
import { SlotPicker } from "./SlotPicker";
import { sessionDateTime } from "./time";
import type { RescheduleSlots } from "./types";
import { errorMessage } from "./useResource";

type Props = {
  sessionId: string | null;
  timezone: string;
  pro?: boolean;
  title?: string;
  confirmText?: string;
  /** Rule text override (free reschedule after a psychologist's cancel). */
  rule?: string;
  onClose: () => void;
  onConfirm: (slot: string) => Promise<void>;
};

/** Slot choice for a reschedule (SEQ-03) or a free new session after a psychologist's cancel (BR-CANC-06). */
export function RescheduleDialog({ sessionId, timezone, pro, title, confirmText, rule, onClose, onConfirm }: Props) {
  const [loaded, setLoaded] = useState<{ id: string; data: RescheduleSlots | null; error: string | null } | null>(null);
  const [slot, setSlot] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!sessionId) return;
    let cancelled = false;
    rescheduleSlots(sessionId, pro).then(
      (data) => !cancelled && setLoaded({ id: sessionId, data, error: null }),
      (e) => !cancelled && setLoaded({ id: sessionId, data: null, error: errorMessage(e, "Не удалось загрузить свободное время.") }),
    );
    return () => {
      cancelled = true;
    };
  }, [sessionId, pro]);

  const current = loaded && loaded.id === sessionId ? loaded : null;

  async function confirm() {
    if (!slot) return;
    setBusy(true);
    setError(null);
    try {
      await onConfirm(slot);
      setSlot(null);
    } catch (e) {
      setError(errorMessage(e, "Не удалось перенести сессию."));
    } finally {
      setBusy(false);
    }
  }

  function close() {
    setSlot(null);
    setError(null);
    onClose();
  }

  return (
    <Modal
      open={sessionId !== null}
      onClose={close}
      title={title ?? "Перенос сессии"}
      footer={
        <>
          <Button variant="secondary" onClick={close}>
            Отмена
          </Button>
          <Button onClick={confirm} disabled={!slot} loading={busy}>
            {confirmText ?? "Перенести"}
          </Button>
        </>
      }
    >
      <div className="grid gap-4">
        {current?.data && <Alert tone={current.data.late ? "warning" : "info"}>{rule ?? rescheduleRuleText(current.data.late, current.data.late_reschedules_left)}</Alert>}
        {!current && <p className="text-muted">Загружаем свободное время…</p>}
        {current?.error && <Alert tone="danger">{current.error}</Alert>}
        {current?.data && (
          <SlotPicker
            slots={current.data.data}
            timezone={timezone}
            value={slot}
            onChange={setSlot}
            emptyText={current.data.late ? "Нет свободного времени позже чем через 12 часов." : undefined}
          />
        )}
        {slot && <p className="text-[15px]">Новое время: <strong>{sessionDateTime(slot, timezone)}</strong></p>}
        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Modal>
  );
}
