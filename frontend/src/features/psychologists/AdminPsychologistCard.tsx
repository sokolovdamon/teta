"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { Alert, Badge, Button, Card, Checkbox, EmptyState, Field, Modal, Textarea } from "@/components/ui";
import { Avatar } from "@/features/catalog/Avatar";
import { ApiError, api } from "@/lib/api";
import { date, dateTime, rub } from "@/lib/format";
import { ACTIVITY, DOCUMENT_KIND, DOCUMENT_STATUS, HISTORY_EVENTS, QUALIFICATION, VIDEO, WORK } from "./labels";
import type { AdminCard, DiffValue, QualificationDocument } from "./types";

type Permissions = { verify: boolean; moderate: boolean; block: boolean };

type Action = {
  title: string;
  description?: ReactNode;
  confirm: string;
  tone?: "primary" | "danger";
  commentLabel: string;
  commentRequired: boolean;
  documents?: boolean;
  run: (comment: string, documentIds: string[]) => Promise<{ data: AdminCard }>;
};

/** ADM-03 card: documents viewer, qualification decision, pending changes diff, video moderation, block. Every action is audited by the API. */
export function AdminPsychologistCard({ id, permissions, timezone }: { id: string; permissions: Permissions; timezone: string }) {
  const [card, setCard] = useState<AdminCard | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [preview, setPreview] = useState<QualificationDocument | null>(null);

  const load = useCallback(() => {
    api<{ data: AdminCard }>(`/admin/psychologists/${id}`)
      .then((r) => setCard(r.data))
      .catch((e: unknown) => setLoadError(e instanceof ApiError && e.status === 404 ? "Психолог не найден." : "Не удалось загрузить карточку."));
  }, [id]);
  useEffect(load, [load]);

  if (loadError) return <Alert tone="danger">{loadError}</Alert>;
  if (!card) return <p className="text-muted">Загрузка…</p>;

  const base = `/admin/psychologists/${card.id}`;
  const post = (path: string, body: Record<string, unknown>) => api<{ data: AdminCard }>(`${base}/${path}`, { method: "POST", body });
  const open = (a: Action) => {
    setNotice(null);
    setAction(a);
  };
  const done = (next: AdminCard, text: string) => {
    setCard(next);
    setAction(null);
    setNotice(text);
  };
  const q = QUALIFICATION[card.qualification_status];
  const w = WORK[card.work_status];
  const reviewable = card.qualification_status === "in_review" || card.qualification_status === "approved";

  async function reviewDocument(doc: QualificationDocument, status: "approved" | "rejected") {
    if (status === "rejected") {
      open({
        title: `Отклонить документ «${doc.title}»`,
        confirm: "Отклонить документ",
        tone: "danger",
        commentLabel: "Причина (увидит психолог)",
        commentRequired: true,
        run: (comment) => post(`documents/${doc.id}/review`, { status, comment }),
      });
      return;
    }
    try {
      const res = await post(`documents/${doc.id}/review`, { status });
      done(res.data, `Документ «${doc.title}» подтверждён.`);
    } catch (e) {
      setNotice(e instanceof ApiError ? e.message : "Не удалось сохранить решение.");
    }
  }

  return (
    <div className="grid gap-6">
      <Link href="/admin/psychologists" className="text-sm text-brand hover:underline">
        ← Все психологи
      </Link>

      <Card className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
        <div className="flex gap-4">
          <Avatar photoUrl={card.photo_url} firstName={card.profile.first_name} lastName={card.profile.last_name} size={88} />
          <div className="grid content-start gap-1.5">
            <h1 className="text-2xl font-semibold tracking-tight">{card.name}</h1>
            <p className="text-sm text-muted">
              {card.user?.email}
              {card.user && !card.user.email_verified && " · email не подтверждён"} · зарегистрирован {date(card.created_at, timezone)}
            </p>
            <div className="flex flex-wrap gap-1.5">
              <Badge tone={q.tone}>{q.label}</Badge>
              <Badge tone={w.tone}>{w.label}</Badge>
              {card.activity_status && <Badge tone={ACTIVITY[card.activity_status].tone}>{ACTIVITY[card.activity_status].label}</Badge>}
              <Badge tone={card.is_published ? "brand" : "neutral"}>{card.is_published ? "Опубликован" : "Не опубликован"}</Badge>
            </div>
            {card.work_status_reason && <p className="text-sm text-ink-2">Причина: {card.work_status_reason}</p>}
            {card.is_published && (
              <Link href={card.public_path} target="_blank" className="text-sm text-brand hover:underline">
                Страница на сайте ↗
              </Link>
            )}
          </div>
        </div>
        {permissions.block && (
          <div className="flex flex-wrap gap-2">
            {card.work_status === "blocked" ? (
              <Button
                variant="secondary"
                onClick={() =>
                  open({
                    title: "Снять блокировку",
                    confirm: "Разблокировать",
                    commentLabel: "Комментарий (необязательно)",
                    commentRequired: false,
                    run: (comment) => post("unblock", { comment: comment || null }),
                  })
                }
              >
                Разблокировать
              </Button>
            ) : (
              <Button
                variant="danger"
                onClick={() =>
                  open({
                    title: "Заблокировать психолога",
                    description: "Профиль скроется из каталога и подбора, новые записи закроются. Психолог получит письмо с причиной.",
                    confirm: "Заблокировать",
                    tone: "danger",
                    commentLabel: "Причина блокировки",
                    commentRequired: true,
                    run: (reason) => post("block", { reason }),
                  })
                }
              >
                Заблокировать
              </Button>
            )}
          </div>
        )}
      </Card>

      {notice && <Alert tone="info">{notice}</Alert>}

      <Card className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Проверка квалификации</h2>
          {permissions.verify && (
            <div className="flex flex-wrap gap-2">
              {card.qualification_status === "in_review" && (
                <>
                  <Button
                    onClick={() =>
                      open({
                        title: "Подтвердить квалификацию",
                        description: "Все документы на проверке будут подтверждены. Профиль опубликуется, если заполнен и есть рабочие интервалы.",
                        confirm: "Подтвердить",
                        commentLabel: "Комментарий (необязательно)",
                        commentRequired: false,
                        run: (comment) => post("qualification/approve", { comment: comment || null }),
                      })
                    }
                  >
                    Подтвердить
                  </Button>
                  <Button
                    variant="danger"
                    onClick={() =>
                      open({
                        title: "Отклонить заявку",
                        description: "Психолог увидит комментарий и сможет исправить данные и подать заявку повторно.",
                        confirm: "Отклонить",
                        tone: "danger",
                        commentLabel: "Комментарий для психолога",
                        commentRequired: true,
                        documents: true,
                        run: (comment, documentIds) => post("qualification/reject", { comment, document_ids: documentIds }),
                      })
                    }
                  >
                    Отклонить
                  </Button>
                </>
              )}
              {card.qualification_status === "approved" && (
                <Button
                  variant="danger"
                  onClick={() =>
                    open({
                      title: "Отозвать квалификацию",
                      description:
                        "Используйте, если документ признан недействительным. Профиль снимется с публикации, предстоящие сессии будут отменены платформой с полным возвратом клиентам.",
                      confirm: "Отозвать",
                      tone: "danger",
                      commentLabel: "Причина (увидит психолог)",
                      commentRequired: true,
                      documents: true,
                      run: (comment, documentIds) => post("qualification/reject", { comment, document_ids: documentIds }),
                    })
                  }
                >
                  Отозвать квалификацию
                </Button>
              )}
            </div>
          )}
        </div>
        <dl className="grid gap-3 text-[15px] sm:grid-cols-3">
          <Info label="Заявка подана">{dateTime(card.qualification.submitted_at, timezone)}</Info>
          <Info label="Квалификация подтверждена">{date(card.qualification.qualified_at, timezone)}</Info>
          <Info label="Комментарий последнего решения">{card.qualification.comment ?? "—"}</Info>
        </dl>
        {card.missing.length > 0 && (
          <Alert tone="warning" title="В профиле не заполнено">
            {card.missing.map((m) => m.label).join(", ")}
          </Alert>
        )}

        <h3 className="mt-2 font-semibold">Документы</h3>
        {card.documents.length === 0 ? (
          <EmptyState title="Документов нет" />
        ) : (
          <ul className="grid gap-3">
            {card.documents.map((d) => (
              <li key={d.id} className="grid gap-2 rounded-xl border border-line p-4 md:grid-cols-[1fr_auto] md:items-center">
                <div className="grid gap-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">{d.title}</p>
                    <Badge tone={DOCUMENT_STATUS[d.status].tone}>{DOCUMENT_STATUS[d.status].label}</Badge>
                  </div>
                  <p className="text-sm text-muted">{[DOCUMENT_KIND[d.kind], d.institution, d.specialty, d.year].filter(Boolean).join(" · ")}</p>
                  {d.comment && <p className="text-sm text-ink-2">Комментарий: {d.comment}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                  {d.file?.url && (
                    <Button variant="secondary" size="sm" onClick={() => setPreview(d)}>
                      Открыть
                    </Button>
                  )}
                  {permissions.verify && reviewable && d.status !== "approved" && (
                    <Button size="sm" onClick={() => reviewDocument(d, "approved")}>
                      Подтвердить
                    </Button>
                  )}
                  {permissions.verify && reviewable && d.status !== "rejected" && (
                    <Button size="sm" variant="ghost" onClick={() => reviewDocument(d, "rejected")}>
                      Отклонить
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
        <p className="text-xs text-muted">Ссылки на файлы временные; каждый просмотр карточки с документами записывается в журнал аудита.</p>
      </Card>

      <Card className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">Изменения профиля на модерации</h2>
          {card.pending && permissions.moderate && (
            <div className="flex flex-wrap gap-2">
              <Button
                onClick={() =>
                  open({
                    title: "Опубликовать изменения",
                    confirm: "Опубликовать",
                    commentLabel: "Комментарий (необязательно)",
                    commentRequired: false,
                    run: (comment) => post("changes/approve", { comment: comment || null }),
                  })
                }
              >
                Одобрить
              </Button>
              <Button
                variant="danger"
                onClick={() =>
                  open({
                    title: "Отклонить изменения",
                    description: "На сайте останется прежняя версия профиля.",
                    confirm: "Отклонить",
                    tone: "danger",
                    commentLabel: "Комментарий для психолога",
                    commentRequired: true,
                    run: (comment) => post("changes/reject", { comment }),
                  })
                }
              >
                Отклонить
              </Button>
            </div>
          )}
        </div>
        {card.pending ? (
          <>
            <p className="text-sm text-muted">Отправлено {dateTime(card.pending.submitted_at, timezone)}. На сайте до решения показывается текущая версия.</p>
            <div className="overflow-x-auto rounded-xl border border-line">
              <table className="w-full min-w-[640px] text-left text-[15px]">
                <thead>
                  <tr className="bg-sunken text-xs uppercase tracking-wider text-muted">
                    <th className="px-4 py-2">Поле</th>
                    <th className="px-4 py-2">Сейчас на сайте</th>
                    <th className="px-4 py-2">На модерации</th>
                  </tr>
                </thead>
                <tbody>
                  {card.pending.diff.map((row) => (
                    <tr key={row.field} className="border-t border-line align-top">
                      <td className="px-4 py-3 font-medium">{row.label}</td>
                      <td className="px-4 py-3 text-ink-2">
                        <DiffCell field={row.field} value={row.before} />
                      </td>
                      <td className="bg-warning-soft/40 px-4 py-3">
                        <DiffCell field={row.field} value={row.after} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          <p className="text-sm text-muted">
            Нет изменений на модерации.
            {card.last_review && ` Последнее решение — ${dateTime(card.last_review.reviewed_at, timezone)}${card.last_review.comment ? `: ${card.last_review.comment}` : ""}.`}
          </p>
        )}
      </Card>

      <Card className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold">Видеовизитка</h2>
            <Badge tone={VIDEO[card.video.status].tone}>{VIDEO[card.video.status].label}</Badge>
          </div>
          {card.video.status === "pending" && permissions.moderate && (
            <div className="flex flex-wrap gap-2">
              <Button
                onClick={() =>
                  open({
                    title: "Опубликовать видеовизитку",
                    confirm: "Опубликовать",
                    commentLabel: "Комментарий (необязательно)",
                    commentRequired: false,
                    run: (comment) => post("video/approve", { comment: comment || null }),
                  })
                }
              >
                Одобрить
              </Button>
              <Button
                variant="danger"
                onClick={() =>
                  open({
                    title: "Отклонить видеовизитку",
                    description: "Видео не появится на сайте; профиль останется опубликованным.",
                    confirm: "Отклонить",
                    tone: "danger",
                    commentLabel: "Комментарий для психолога",
                    commentRequired: true,
                    run: (comment) => post("video/reject", { comment }),
                  })
                }
              >
                Отклонить
              </Button>
            </div>
          )}
        </div>
        {card.video.status === "none" ? (
          <p className="text-sm text-muted">Психолог не загружал видеовизитку.</p>
        ) : (
          <div className="grid gap-4 md:grid-cols-2">
            {card.video.url && card.video.status !== "approved" && (
              <figure className="grid gap-2">
                <video src={card.video.url} controls preload="metadata" className="aspect-video w-full rounded-xl bg-graphite" />
                <figcaption className="text-sm text-muted">
                  {card.video.status === "pending" ? "На модерации" : "Отклонена"}
                  {card.video.duration_sec ? ` · ${card.video.duration_sec} с` : ""}
                  {card.video.comment ? ` · ${card.video.comment}` : ""}
                </figcaption>
              </figure>
            )}
            {card.video.approved_url && (
              <figure className="grid gap-2">
                <video src={card.video.approved_url} controls preload="metadata" className="aspect-video w-full rounded-xl bg-graphite" />
                <figcaption className="text-sm text-muted">На сайте сейчас</figcaption>
              </figure>
            )}
          </div>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="grid content-start gap-3">
          <h2 className="text-lg font-semibold">Профиль на сайте</h2>
          <Info label="Коротко о себе">{card.profile.headline ?? "—"}</Info>
          <Info label="О себе">
            <span className="whitespace-pre-line">{card.profile.about ?? "—"}</span>
          </Info>
          <Info label="Опыт">{card.profile.experience_years !== null ? `${card.profile.experience_years} лет` : "—"}</Info>
          <Info label="Подходы">
            {card.profile.approaches.length ? (
              <ul className="grid gap-1">
                {card.profile.approaches.map((a) => (
                  <li key={a.id}>
                    <span className="font-medium">{a.title}</span>
                    {a.explanation && <span className="text-muted"> — {a.explanation}</span>}
                  </li>
                ))}
              </ul>
            ) : (
              "—"
            )}
          </Info>
          <Info label="Запросы">{card.profile.requests.join(", ") || "—"}</Info>
          <Info label="Специализации">{card.profile.specializations.join(", ") || "—"}</Info>
          <Info label="Образование">
            {card.profile.education.length ? card.profile.education.map((e) => [e.institution, e.specialty, e.year].filter(Boolean).join(", ")).join("; ") : "—"}
          </Info>
        </Card>
        <div className="grid content-start gap-6">
          <Card className="grid gap-3">
            <h2 className="text-lg font-semibold">Цены и запись</h2>
            <Info label="Индивидуальная">{card.formats.works_individual ? rub(card.prices.price_individual) : "не проводит"}</Info>
            <Info label="Парная">{card.formats.works_pair ? rub(card.prices.price_pair) : "не проводит"}</Info>
            <Info label="Ценовая категория">{card.prices.price_category?.title ?? "—"}</Info>
            <Info label="Рабочих интервалов">{card.schedule.intervals}</Info>
            <Info label="Ближайшее свободное время">{card.schedule.nearest_slot ? dateTime(card.schedule.nearest_slot, timezone) : "—"}</Info>
            <Info label="Просмотров страницы">{card.views_count}</Info>
            {card.price_history.length > 0 && (
              <details className="text-sm">
                <summary className="cursor-pointer text-muted">История цен</summary>
                <ul className="mt-2 grid gap-1">
                  {card.price_history.map((h, i) => (
                    <li key={i}>
                      {dateTime(h.created_at, timezone)} — {rub(h.price_individual)}
                      {h.price_pair ? ` / пара ${rub(h.price_pair)}` : ""}
                    </li>
                  ))}
                </ul>
              </details>
            )}
          </Card>
          <Card className="grid gap-3">
            <h2 className="text-lg font-semibold">Супервизия (ST-09)</h2>
            <Info label="Статус активности">{card.activity_status ? ACTIVITY[card.activity_status].label : "Квалификация не подтверждена"}</Info>
            <Info label={`Требование за ${card.activity.month.slice(0, 7)}`}>
              {!card.activity.applies ? "не действует (льготный период)" : card.activity.met ? "выполнено" : `не выполнено, срок — ${date(card.activity.deadline, timezone)}`}
            </Info>
          </Card>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">История проверки квалификации</h2>
          <History
            items={card.qualification.history.map((h) => ({ at: h.at, title: HISTORY_EVENTS[h.to] ?? h.to, text: h.comment, actor: h.actor ?? null }))}
            timezone={timezone}
          />
        </Card>
        <Card className="grid gap-3">
          <h2 className="text-lg font-semibold">История статуса работы</h2>
          <History items={card.work_status_history.map((h) => ({ at: h.at, title: WORK[h.to as keyof typeof WORK]?.label ?? h.to, text: h.reason, actor: h.actor }))} timezone={timezone} />
        </Card>
      </div>

      {action && <ActionModal action={action} documents={card.documents} onClose={() => setAction(null)} onDone={(next) => done(next, "Решение сохранено и записано в журнал аудита.")} />}

      <Modal open={preview !== null} onClose={() => setPreview(null)} title={preview?.title ?? "Документ"}>
        {preview?.file?.url && (
          <div className="grid gap-3">
            {preview.file.mime_type.startsWith("image/") ? (
              // eslint-disable-next-line @next/next/no-img-element -- private file behind a short-lived signed link
              <img src={preview.file.url} alt={preview.title} className="max-h-[70vh] w-full rounded-xl object-contain" />
            ) : (
              <iframe src={preview.file.url} title={preview.title} className="h-[70vh] w-full rounded-xl border border-line" />
            )}
            <a href={preview.file.url} target="_blank" rel="noopener noreferrer" className="text-sm text-brand hover:underline">
              Открыть в новой вкладке ↗
            </a>
          </div>
        )}
      </Modal>
    </div>
  );
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wider text-muted">{label}</dt>
      <dd className="mt-0.5 text-ink-2">{children}</dd>
    </div>
  );
}

function History({ items, timezone }: { items: { at: string | null; title: string; text: string | null; actor: string | null }[]; timezone: string }) {
  if (items.length === 0) return <p className="text-sm text-muted">Записей нет.</p>;
  return (
    <ol className="grid gap-3 border-l-2 border-line pl-4">
      {items.map((h, i) => (
        <li key={i}>
          <p className="text-sm text-muted">
            {dateTime(h.at, timezone)}
            {h.actor ? ` · ${h.actor}` : ""}
          </p>
          <p className="font-medium">{h.title}</p>
          {h.text && <p className="whitespace-pre-line text-ink-2">{h.text}</p>}
        </li>
      ))}
    </ol>
  );
}

function DiffCell({ field, value }: { field: string; value: DiffValue }) {
  if (value === null || value === "" || (Array.isArray(value) && value.length === 0)) return <span className="text-muted">—</span>;
  if (field === "photo_file_id" && typeof value === "string") {
    // eslint-disable-next-line @next/next/no-img-element -- moderation preview of an uploaded photo
    return <img src={value} alt="Фото" className="size-24 rounded-xl object-cover" />;
  }
  if (Array.isArray(value)) {
    return (
      <ul className="grid gap-1">
        {value.map((v, i) =>
          typeof v === "string" ? (
            <li key={i}>{v}</li>
          ) : "title" in v ? (
            <li key={i}>
              <span className="font-medium">{v.title}</span>
              {v.explanation && <span className="text-muted"> — {v.explanation}</span>}
            </li>
          ) : (
            <li key={i}>{[v.institution, v.specialty, v.year].filter(Boolean).join(", ")}</li>
          ),
        )}
      </ul>
    );
  }
  return <span className="whitespace-pre-line">{String(value)}</span>;
}

function ActionModal({
  action,
  documents,
  onClose,
  onDone,
}: {
  action: Action;
  documents: QualificationDocument[];
  onClose: () => void;
  onDone: (card: AdminCard) => void;
}) {
  const [comment, setComment] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    if (action.commentRequired && comment.trim().length < 3) {
      setError("Комментарий обязателен.");
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await action.run(comment.trim(), selected);
      onDone(res.data);
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось выполнить действие.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={action.title}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Отмена
          </Button>
          <Button variant={action.tone === "danger" ? "danger" : "primary"} onClick={confirm} loading={busy}>
            {action.confirm}
          </Button>
        </>
      }
    >
      <div className="grid gap-4">
        {action.description && <p className="text-ink-2">{action.description}</p>}
        {action.documents && documents.length > 0 && (
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium text-ink-2">Документы, признанные недействительными</legend>
            {documents.map((d) => (
              <Checkbox
                key={d.id}
                label={d.title}
                checked={selected.includes(d.id)}
                onChange={() => setSelected((s) => (s.includes(d.id) ? s.filter((x) => x !== d.id) : [...s, d.id]))}
              />
            ))}
          </fieldset>
        )}
        <Field label={action.commentLabel} error={error}>
          <Textarea value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} rows={4} />
        </Field>
      </div>
    </Modal>
  );
}
