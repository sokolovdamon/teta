"use client";

import { clsx } from "clsx";
import Link from "next/link";
import { useId, useState, type ReactNode } from "react";
import { Alert, Button, Chip, Textarea } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { MOODS, NOTE_MAX } from "./moods";
import type { DiaryEntry, EmotionTag } from "./types";

/** The 5-emoji scale as a radio group: keyboard and screen readers work natively. */
export function MoodPicker({ value, onChange, legend }: { value: number | null; onChange: (v: number) => void; legend: ReactNode }) {
  const name = useId();
  return (
    <fieldset className="grid gap-3">
      <legend className="mb-3 text-base font-medium">{legend}</legend>
      <div className="grid grid-cols-5 gap-2">
        {MOODS.map((m) => (
          <label
            key={m.value}
            className={clsx(
              "grid cursor-pointer justify-items-center gap-1 rounded-2xl border px-1 py-3 text-center transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand",
              value === m.value ? "border-brand bg-brand-soft" : "border-line bg-surface hover:border-brand-tint",
            )}
          >
            <input type="radio" name={name} value={m.value} checked={value === m.value} onChange={() => onChange(m.value)} className="sr-only" />
            <span className="text-3xl leading-none sm:text-4xl" aria-hidden>
              {m.emoji}
            </span>
            <span className="text-xs text-ink-2 sm:text-sm">{m.label}</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

export function TagPicker({ tags, value, onChange }: { tags: EmotionTag[]; value: string[]; onChange: (v: string[]) => void }) {
  if (tags.length === 0) return null;
  return (
    <div className="grid gap-2">
      <p className="text-sm font-medium text-ink-2">Какие эмоции сейчас есть? Можно выбрать несколько или ничего</p>
      <div className="flex flex-wrap gap-2">
        {tags.map((t) => (
          <Chip key={t.id} active={value.includes(t.id)} onClick={() => onChange(value.includes(t.id) ? value.filter((id) => id !== t.id) : [...value, t.id])}>
            {t.title}
          </Chip>
        ))}
      </div>
    </div>
  );
}

type Props = {
  tags: EmotionTag[];
  onSaved: (entry: DiaryEntry) => void;
  /** Secondary action next to «Сохранить», e.g. «Пропустить» in the check-in. */
  secondary?: ReactNode;
  legend?: ReactNode;
};

/** CL-01 / CL-06: mood, optional tags and an optional private note. */
export function EntryForm({ tags, onSaved, secondary, legend = "Как вы себя чувствуете сейчас?" }: Props) {
  const [mood, setMood] = useState<number | null>(null);
  const [tagIds, setTagIds] = useState<string[]>([]);
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const noteId = useId();

  async function save() {
    if (mood === null) {
      setError("Выберите, как вы себя чувствуете.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const res = await api<{ data: DiaryEntry }>("/diary/entries", { method: "POST", body: { mood, tag_ids: tagIds, note: note.trim() || null } });
      setMood(null);
      setTagIds([]);
      setNote("");
      onSaved(res.data);
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось сохранить. Проверьте соединение и попробуйте ещё раз.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <form
      className="grid gap-5"
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <MoodPicker value={mood} onChange={setMood} legend={legend} />
      {mood === 1 && (
        <Alert tone="info">
          Если сейчас очень тяжело, не оставайтесь с этим в одиночку: на странице{" "}
          <Link href="/help-now" className="font-medium text-brand underline">
            «Экстренная помощь»
          </Link>{" "}
          — телефоны служб, которые помогают круглосуточно.
        </Alert>
      )}
      <TagPicker tags={tags} value={tagIds} onChange={setTagIds} />
      <div className="grid gap-1.5">
        <label htmlFor={noteId} className="text-sm font-medium text-ink-2">
          Заметка для себя
        </label>
        <Textarea id={noteId} value={note} maxLength={NOTE_MAX} onChange={(e) => setNote(e.target.value)} placeholder="Что происходит, что помогло — по желанию" />
        <p className="flex justify-between gap-3 text-sm text-muted">
          <span>Заметку видите только вы. Психолог видит лишь настроение и метки.</span>
          <span className="num shrink-0">
            {note.length}/{NOTE_MAX}
          </span>
        </p>
      </div>
      {error && <Alert tone="danger">{error}</Alert>}
      <div className="flex flex-wrap justify-end gap-3">
        {secondary}
        <Button type="submit" loading={saving}>
          Сохранить
        </Button>
      </div>
    </form>
  );
}
