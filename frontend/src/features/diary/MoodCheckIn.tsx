"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { Button, Modal } from "@/components/ui";
import { api } from "@/lib/api";
import { EntryForm } from "./EntryForm";
import type { DiaryPrompt } from "./types";

/**
 * CL-01: mood check-in when the client enters the cabinet — not more than once a day (the backend decides),
 * never blocking: closing the window counts as «Пропустить».
 */
export function MoodCheckIn({ initialShow }: { initialShow: boolean }) {
  const router = useRouter();
  const [prompt, setPrompt] = useState<DiaryPrompt | null>(null);
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const skipped = useRef(false);

  useEffect(() => {
    if (!initialShow) return;
    let alive = true;
    api<{ data: DiaryPrompt }>("/diary/prompt")
      .then((res) => {
        if (alive && res.data.show) {
          setPrompt(res.data);
          setOpen(true);
        }
      })
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [initialShow]);

  function skip() {
    setOpen(false);
    if (saved || skipped.current) return;
    skipped.current = true;
    api("/diary/skip", { method: "POST" }).catch(() => undefined);
  }

  if (!prompt) return null;

  return (
    <Modal open={open} onClose={skip} title={saved ? "Спасибо!" : "Отметка настроения"}>
      {saved ? (
        <div className="grid gap-4">
          <p className="text-ink-2">Отметка сохранена в дневнике эмоций. Динамику можно посмотреть в разделе «Дневник эмоций».</p>
          <div className="flex justify-end">
            <Button type="button" onClick={() => setOpen(false)}>
              Хорошо
            </Button>
          </div>
        </div>
      ) : (
        <EntryForm
          tags={prompt.tags}
          onSaved={() => {
            setSaved(true);
            router.refresh();
          }}
          secondary={
            <Button type="button" variant="ghost" onClick={skip}>
              Пропустить
            </Button>
          }
        />
      )}
    </Modal>
  );
}
