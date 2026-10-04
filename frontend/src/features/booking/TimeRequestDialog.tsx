"use client";

import { useState } from "react";
import { Alert, Button, Chip, Field, Input, Modal, Textarea } from "@/components/ui";
import { createTimeRequest } from "./api";
import { errorMessage } from "./useResource";

const WEEKDAYS: [number, string][] = [
  [1, "Пн"],
  [2, "Вт"],
  [3, "Ср"],
  [4, "Чт"],
  [5, "Пт"],
  [6, "Сб"],
  [7, "Вс"],
];

type Props = {
  psychologist: { id: string; name: string } | null;
  onClose: () => void;
  onSent: () => void;
};

/** "Нет подходящего времени" (DEC-28): days and hours that suit the client go to the psychologist's inbox. */
export function TimeRequestDialog({ psychologist, onClose, onSent }: Props) {
  const [days, setDays] = useState<number[]>([]);
  const [from, setFrom] = useState("18:00");
  const [to, setTo] = useState("21:00");
  const [comment, setComment] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function toggle(day: number) {
    setDays((d) => (d.includes(day) ? d.filter((x) => x !== day) : [...d, day].sort()));
  }

  async function submit() {
    if (!psychologist) return;
    if (days.length === 0) {
      setError("Выберите хотя бы один день.");
      return;
    }
    if (from >= to) {
      setError("Время «до» должно быть позже времени «с».");
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await createTimeRequest({
        psychologist_id: psychologist.id,
        preferred: days.map((weekday) => ({ weekday, from, to })),
        comment: comment.trim() || undefined,
      });
      setDays([]);
      setComment("");
      onSent();
    } catch (e) {
      setError(errorMessage(e, "Не удалось отправить запрос."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open={psychologist !== null}
      onClose={onClose}
      title="Нет подходящего времени"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Отмена
          </Button>
          <Button onClick={submit} loading={busy}>
            Отправить запрос
          </Button>
        </>
      }
    >
      <div className="grid gap-4">
        <p className="text-ink-2">
          Расскажите психологу {psychologist?.name}, когда вам удобно. Он сможет открыть подходящее время и предложить его — вы получите уведомление.
        </p>
        <div className="grid gap-2">
          <span className="text-sm font-medium text-ink-2">Удобные дни</span>
          <div className="flex flex-wrap gap-2">
            {WEEKDAYS.map(([n, label]) => (
              <Chip key={n} active={days.includes(n)} onClick={() => toggle(n)}>
                {label}
              </Chip>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="С">
            <Input type="time" value={from} onChange={(e) => setFrom(e.target.value)} step={1800} />
          </Field>
          <Field label="До">
            <Input type="time" value={to} onChange={(e) => setTo(e.target.value)} step={1800} />
          </Field>
        </div>
        <Field label="Комментарий" hint="Необязательно. Не пишите здесь о своём состоянии — только об удобном времени.">
          <Textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={1000} />
        </Field>
        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Modal>
  );
}
