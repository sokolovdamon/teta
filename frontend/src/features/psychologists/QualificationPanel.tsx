"use client";

import Link from "next/link";
import { useRef, useState } from "react";
import { Alert, Badge, Button, Card, EmptyState, Field, Input, Select } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { date, dateTime } from "@/lib/format";
import { DOCUMENT_KIND, DOCUMENT_STATUS, HISTORY_EVENTS, QUALIFICATION } from "./labels";
import type { DocumentKind, QualificationView } from "./types";

const PROFILE_CODES = new Set(["first_name", "last_name", "gender", "birth_year", "headline", "about", "experience_years", "education", "approaches", "requests", "formats", "price_individual", "price_pair"]);

function sizeLabel(bytes: number): string {
  return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`;
}

/** PRO-01: documents, submission and decisions (ST-08): «На модерации», «Подтверждён», «Отклонён» с комментарием. */
export function QualificationPanel({ initial, timezone }: { initial: QualificationView; timezone: string }) {
  const [view, setView] = useState(initial);
  const [notice, setNotice] = useState<{ tone: "success" | "danger" | "info"; text: string; list?: string[] } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const status = QUALIFICATION[view.status];

  async function submit() {
    setSubmitting(true);
    setNotice(null);
    try {
      const res = await api<{ data: QualificationView }>("/pro/qualification/submit", { method: "POST" });
      setView(res.data);
      setNotice({ tone: "success", text: "Заявка отправлена. Администратор проверит документы и профиль и напишет о решении на email." });
    } catch (e) {
      if (e instanceof ApiError) {
        const list = e.errors.qualification;
        setNotice({ tone: "danger", text: list ? "Чтобы отправить заявку, не хватает:" : e.message, list });
      } else setNotice({ tone: "danger", text: "Не удалось отправить заявку. Попробуйте ещё раз." });
    } finally {
      setSubmitting(false);
    }
  }

  async function remove(id: string) {
    if (!window.confirm("Удалить документ?")) return;
    try {
      const res = await api<{ data: QualificationView }>(`/pro/qualification/documents/${id}`, { method: "DELETE" });
      setView(res.data);
    } catch (e) {
      setNotice({ tone: "danger", text: e instanceof ApiError ? (e.field("document") ?? e.message) : "Не удалось удалить документ." });
    }
  }

  return (
    <div className="grid max-w-4xl gap-6">
      <Card className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-3">
            <h2 className="text-lg font-semibold">Статус проверки</h2>
            <Badge tone={status.tone}>{status.label}</Badge>
          </div>
          {view.can_submit && (
            <Button onClick={submit} loading={submitting}>
              {view.status === "rejected" ? "Отправить повторно" : "Отправить на модерацию"}
            </Button>
          )}
        </div>
        {view.status === "draft" && (
          <p className="text-ink-2">
            Заполните <Link href="/pro/profile" className="text-brand hover:underline">профиль</Link>, загрузите диплом о психологическом образовании или о
            профессиональной переподготовке и отправьте заявку. Пока квалификация не подтверждена, профиль не виден клиентам.
          </p>
        )}
        {view.status === "in_review" && (
          <p className="text-ink-2">
            Заявка на модерации с {dateTime(view.submitted_at, timezone)}. Пока идёт проверка, документы менять нельзя. Решение придёт на email и в центр
            уведомлений.
          </p>
        )}
        {view.status === "approved" && (
          <p className="text-ink-2">
            Квалификация подтверждена {date(view.qualified_at, timezone)}. Новые документы можно добавлять — они проверяются отдельно, статус «Подтверждён»
            сохраняется.
          </p>
        )}
        {view.status === "rejected" && view.comment && (
          <Alert tone="danger" title="Комментарий администратора">
            <p className="whitespace-pre-line">{view.comment}</p>
            <p className="mt-2">Исправьте данные или документы и отправьте заявку повторно — число подач не ограничено.</p>
          </Alert>
        )}
        {notice && (
          <Alert tone={notice.tone}>
            {notice.text}
            {notice.list && (
              <ul className="mt-1 list-disc pl-5">
                {notice.list.map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ul>
            )}
          </Alert>
        )}
        {view.can_submit && view.missing.length > 0 && (
          <div className="rounded-xl bg-ground p-4">
            <p className="mb-2 font-medium">Что осталось сделать</p>
            <ul className="grid gap-1.5 text-[15px]">
              {view.missing.map((m) => (
                <li key={m.code} className="flex gap-2">
                  <span aria-hidden className="text-warning">
                    ○
                  </span>
                  <span>
                    <span className="font-medium">{m.label}</span> — <span className="text-ink-2">{m.hint}</span>{" "}
                    {PROFILE_CODES.has(m.code) && (
                      <Link href="/pro/profile" className="text-sm text-brand hover:underline">
                        заполнить
                      </Link>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Card>

      <Card className="grid gap-4">
        <h2 className="text-lg font-semibold">Документы</h2>
        {view.documents.length === 0 ? (
          <EmptyState title="Документов пока нет" description="Загрузите диплом о психологическом образовании или о профессиональной переподготовке — это обязательно." />
        ) : (
          <ul className="grid gap-3">
            {view.documents.map((d) => {
              const s = DOCUMENT_STATUS[d.status];
              return (
                <li key={d.id} className="grid gap-2 rounded-xl border border-line p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="font-medium">{d.title}</p>
                      <p className="text-sm text-muted">{[DOCUMENT_KIND[d.kind], d.institution, d.specialty, d.year].filter(Boolean).join(" · ")}</p>
                    </div>
                    <Badge tone={s.tone}>{s.label}</Badge>
                  </div>
                  {d.status === "rejected" && d.comment && <p className="text-sm text-danger">Комментарий: {d.comment}</p>}
                  <div className="flex flex-wrap items-center gap-3 text-sm">
                    {d.file?.url && (
                      <a href={d.file.url} target="_blank" rel="noopener noreferrer" className="text-brand hover:underline">
                        {d.file.name} ({sizeLabel(d.file.size)})
                      </a>
                    )}
                    {d.can_delete && (
                      <button type="button" className="text-muted hover:text-danger" onClick={() => remove(d.id)}>
                        Удалить
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
        {view.can_upload ? (
          <UploadForm kinds={view.document_kinds} onUploaded={setView} />
        ) : (
          <p className="text-sm text-muted">Пока заявка на модерации, документы менять нельзя.</p>
        )}
      </Card>

      {view.history.length > 0 && (
        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">История заявок и решений</h2>
          <ol className="grid gap-3 border-l-2 border-line pl-4">
            {view.history.map((h, i) => (
              <li key={i} className="grid gap-0.5">
                <p className="text-sm text-muted">{dateTime(h.at, timezone)}</p>
                <p className="font-medium">{HISTORY_EVENTS[h.to] ?? h.to}</p>
                {h.comment && <p className="whitespace-pre-line text-ink-2">{h.comment}</p>}
              </li>
            ))}
          </ol>
        </Card>
      )}
    </div>
  );
}

function UploadForm({ kinds, onUploaded }: { kinds: DocumentKind[]; onUploaded: (v: QualificationView) => void }) {
  const fileInput = useRef<HTMLInputElement>(null);
  const [kind, setKind] = useState<DocumentKind>("diploma");
  const [title, setTitle] = useState("");
  const [institution, setInstitution] = useState("");
  const [specialty, setSpecialty] = useState("");
  const [year, setYear] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  async function send(e: React.FormEvent) {
    e.preventDefault();
    const file = fileInput.current?.files?.[0];
    if (!file) {
      setErrors({ file: "Выберите файл: PDF, JPG или PNG" });
      return;
    }
    setBusy(true);
    setErrors({});
    setDone(false);
    const body = new FormData();
    body.append("file", file);
    body.append("kind", kind);
    body.append("title", title);
    if (institution) body.append("institution", institution);
    if (specialty) body.append("specialty", specialty);
    if (year) body.append("year", year);
    try {
      const res = await api<{ data: QualificationView }>("/pro/qualification/documents", { method: "POST", body });
      onUploaded(res.data);
      setTitle("");
      setInstitution("");
      setSpecialty("");
      setYear("");
      if (fileInput.current) fileInput.current.value = "";
      setDone(true);
    } catch (err) {
      if (err instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) flat[k] = v[0];
        setErrors(Object.keys(flat).length ? flat : { file: err.message });
      } else setErrors({ file: "Не удалось загрузить файл." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={send} className="grid gap-4 rounded-xl bg-ground p-4">
      <p className="font-medium">Добавить документ</p>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Тип документа" error={errors.kind}>
          <Select value={kind} onChange={(e) => setKind(e.target.value as DocumentKind)}>
            {kinds.map((k) => (
              <option key={k} value={k}>
                {DOCUMENT_KIND[k]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Название" hint="Например: «Диплом специалиста, психология»" error={errors.title}>
          <Input value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={255} />
        </Field>
        <Field label="Учебное заведение" error={errors.institution}>
          <Input value={institution} onChange={(e) => setInstitution(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Специальность или программа" error={errors.specialty}>
          <Input value={specialty} onChange={(e) => setSpecialty(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Год выдачи" error={errors.year}>
          <Input inputMode="numeric" value={year} onChange={(e) => setYear(e.target.value.replace(/\D/g, "").slice(0, 4))} />
        </Field>
        <Field label="Файл" hint="PDF, JPG или PNG, до 20 МБ" error={errors.file}>
          <input ref={fileInput} type="file" accept="application/pdf,image/jpeg,image/png" className="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-2 file:text-brand" />
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" variant="secondary" loading={busy}>
          Загрузить
        </Button>
        {done && <span className="text-sm text-success">Документ загружен</span>}
      </div>
    </form>
  );
}
