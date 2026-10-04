"use client";

import { useId, useState } from "react";
import { Alert, Button, Chip, Field, Input, Select, Textarea } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { dateTime } from "@/lib/format";
import { TYPE_LABELS, fileSize, isValidLinkUrl } from "./labels";
import type { EligibleSession, RecoFile, RecoType, Recommendation } from "./types";

const ACCEPT = ".pdf,.jpg,.jpeg,.png,.mp3,.m4a,.txt,.docx";

type LinkRow = { url: string; title: string };

type Props = {
  sessions: EligibleSession[];
  timezone: string;
  /** Draft being edited; a new recommendation otherwise. */
  draft?: Recommendation | null;
  onDone: (reco: Recommendation) => void;
  onCancel: () => void;
};

/** PRO-07 editor: type, title, text, links (incl. knowledge base pages), files and an optional due date. */
export function RecoForm({ sessions, timezone, draft, onDone, onCancel }: Props) {
  const [sessionId, setSessionId] = useState(draft?.session_id ?? sessions[0]?.id ?? "");
  const [type, setType] = useState<RecoType>(draft?.type ?? "task");
  const [title, setTitle] = useState(draft?.title ?? "");
  const [body, setBody] = useState(draft?.body ?? "");
  const [dueDate, setDueDate] = useState(draft?.due_date ?? "");
  const [links, setLinks] = useState<LinkRow[]>(draft?.links.map((l) => ({ url: l.url, title: l.title ?? "" })) ?? []);
  const [files, setFiles] = useState<RecoFile[]>(draft?.files ?? []);
  const [uploading, setUploading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState<"draft" | "send" | null>(null);
  const fileInput = useId();

  async function upload(list: FileList | null) {
    if (!list?.length) return;
    setUploading(true);
    setMessage(null);
    try {
      for (const file of Array.from(list)) {
        const form = new FormData();
        form.append("file", file);
        form.append("purpose", "recommendation");
        const res = await api<{ data: RecoFile }>("/files", { method: "POST", body: form });
        setFiles((prev) => [...prev, res.data]);
      }
    } catch (e) {
      setMessage(e instanceof ApiError ? (e.field("file") ?? e.message) : "Не удалось загрузить файл.");
    } finally {
      setUploading(false);
    }
  }

  function validate(): boolean {
    const next: Record<string, string> = {};
    if (!draft && !sessionId) next.session_id = "Выберите сессию.";
    if (title.trim().length < 2) next.title = "Добавьте заголовок.";
    links.forEach((l, i) => {
      if (l.url.trim() && !isValidLinkUrl(l.url)) next[`links.${i}.url`] = "Ссылка должна начинаться с https:// или быть адресом страницы платформы.";
    });
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  async function submit(send: boolean) {
    if (!validate()) return;
    setSaving(send ? "send" : "draft");
    setMessage(null);
    const payload = {
      type,
      title: title.trim(),
      body: body.trim() || null,
      due_date: dueDate || null,
      links: links.filter((l) => l.url.trim()).map((l) => ({ url: l.url.trim(), title: l.title.trim() || null })),
      file_ids: files.map((f) => f.id),
    };
    try {
      let reco: Recommendation;
      if (draft) {
        reco = (await api<{ data: Recommendation }>(`/pro/recommendations/${draft.id}`, { method: "PATCH", body: payload })).data;
        if (send) reco = (await api<{ data: Recommendation }>(`/pro/recommendations/${draft.id}/send`, { method: "POST" })).data;
      } else {
        reco = (await api<{ data: Recommendation }>("/pro/recommendations", { method: "POST", body: { ...payload, session_id: sessionId, send } })).data;
      }
      onDone(reco);
    } catch (e) {
      if (e instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.errors)) flat[k] = v[0];
        setErrors(flat);
        setMessage(Object.keys(flat).length ? (flat.session_id ?? flat.file_ids ?? null) : e.message);
      } else {
        setMessage("Не удалось связаться с сервером. Попробуйте ещё раз.");
      }
    } finally {
      setSaving(null);
    }
  }

  return (
    <form
      className="grid gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        void submit(true);
      }}
    >
      {draft ? (
        <p className="text-sm text-muted">{draft.session_starts_at ? `После сессии ${dateTime(draft.session_starts_at, timezone)}` : null}</p>
      ) : (
        <Field label="Сессия" error={errors.session_id} hint="Рекомендацию можно прикрепить к проведённой сессии, пока не закрылось окно после неё.">
          <Select value={sessionId} onChange={(e) => setSessionId(e.target.value)}>
            {sessions.map((s) => (
              <option key={s.id} value={s.id}>
                {dateTime(s.starts_at, timezone)} · до {dateTime(s.window_until, timezone)}
              </option>
            ))}
          </Select>
        </Field>
      )}

      <div className="grid gap-2">
        <span className="text-sm font-medium text-ink-2">Тип</span>
        <div className="flex flex-wrap gap-2" role="group" aria-label="Тип рекомендации">
          {(Object.keys(TYPE_LABELS) as RecoType[]).map((t) => (
            <Chip key={t} active={type === t} onClick={() => setType(t)}>
              {TYPE_LABELS[t]}
            </Chip>
          ))}
        </div>
      </div>

      <Field label="Заголовок" error={errors.title}>
        <Input value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} placeholder="Например, «Дыхание 4-7-8 перед сном»" aria-invalid={!!errors.title} />
      </Field>
      <Field label="Текст" error={errors.body} hint="Клиент увидит текст в кабинете. В письмо он не попадает.">
        <Textarea value={body} maxLength={10000} onChange={(e) => setBody(e.target.value)} rows={6} />
      </Field>

      <div className="grid gap-2">
        <span className="text-sm font-medium text-ink-2">Ссылки и материалы базы знаний</span>
        {links.map((l, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
            <Field error={errors[`links.${i}.url`]}>
              <Input
                value={l.url}
                placeholder="https://… или /client/materials/…"
                aria-label="Адрес ссылки"
                aria-invalid={!!errors[`links.${i}.url`]}
                onChange={(e) => setLinks(links.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)))}
              />
            </Field>
            <Input value={l.title} placeholder="Название (необязательно)" aria-label="Название ссылки" onChange={(e) => setLinks(links.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
            <Button type="button" variant="ghost" onClick={() => setLinks(links.filter((_, j) => j !== i))} aria-label="Убрать ссылку">
              Убрать
            </Button>
          </div>
        ))}
        {links.length < 10 && (
          <div>
            <Button type="button" variant="secondary" size="sm" onClick={() => setLinks([...links, { url: "", title: "" }])}>
              Добавить ссылку
            </Button>
          </div>
        )}
      </div>

      <div className="grid gap-2">
        <label htmlFor={fileInput} className="text-sm font-medium text-ink-2">
          Файлы
        </label>
        {files.length > 0 && (
          <ul className="grid gap-1.5">
            {files.map((f) => (
              <li key={f.id} className="flex flex-wrap items-center gap-2 text-sm">
                <span aria-hidden>📎</span>
                <span className="font-medium">{f.name}</span>
                <span className="text-muted">{fileSize(f.size)}</span>
                <button type="button" className="text-muted hover:text-danger" onClick={() => setFiles(files.filter((x) => x.id !== f.id))}>
                  Убрать
                </button>
              </li>
            ))}
          </ul>
        )}
        {files.length < 10 && (
          <input
            id={fileInput}
            type="file"
            multiple
            accept={ACCEPT}
            disabled={uploading}
            onChange={(e) => {
              void upload(e.target.files);
              e.target.value = "";
            }}
            className="text-sm file:mr-3 file:rounded-xl file:border file:border-line-strong file:bg-surface file:px-3 file:py-2 file:text-ink"
          />
        )}
        <p className="text-sm text-muted">{uploading ? "Загружаем файл…" : "PDF, изображения, аудио, документы Word или текст."}</p>
        {errors.file_ids && <p className="text-sm text-danger">{errors.file_ids}</p>}
      </div>

      <Field label="Срок (необязательно)" error={errors.due_date}>
        <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} className="sm:max-w-56" />
      </Field>

      {message && <Alert tone="danger">{message}</Alert>}

      <div className="flex flex-wrap justify-end gap-3">
        <Button type="button" variant="ghost" onClick={onCancel}>
          Отмена
        </Button>
        <Button type="button" variant="secondary" loading={saving === "draft"} disabled={saving !== null || uploading} onClick={() => void submit(false)}>
          Сохранить черновик
        </Button>
        <Button type="submit" loading={saving === "send"} disabled={saving !== null || uploading}>
          Отправить клиенту
        </Button>
      </div>
    </form>
  );
}
