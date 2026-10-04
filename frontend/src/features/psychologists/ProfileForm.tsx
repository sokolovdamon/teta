"use client";

import Link from "next/link";
import { useRef, useState, type ReactNode } from "react";
import { Alert, Badge, Button, Card, Checkbox, Chip, Field, Input, Select, Textarea } from "@/components/ui";
import { Avatar } from "@/features/catalog/Avatar";
import type { Dictionaries } from "@/features/catalog/types";
import { ApiError, api } from "@/lib/api";
import { dateTime, rub } from "@/lib/format";
import { QUALIFICATION, VIDEO, WORK } from "./labels";
import { categoryFor, kopecksToRubles, rublesToKopecks } from "./schedule";
import type { EducationItem, OwnProfile } from "./types";

type Props = { initial: OwnProfile; dictionaries: Dictionaries };

type Form = {
  first_name: string;
  last_name: string;
  gender: "" | "female" | "male";
  birth_year: string;
  experience_years: string;
  headline: string;
  about: string;
  education: { institution: string; specialty: string; year: string }[];
  approaches: Record<string, string>;
  specializations: string[];
  requests: string[];
  works_individual: boolean;
  works_pair: boolean;
  price_individual: string;
  price_pair: string;
};

function toForm(p: OwnProfile): Form {
  const v = p.values;
  return {
    first_name: v.first_name ?? "",
    last_name: v.last_name ?? "",
    gender: v.gender ?? "",
    birth_year: v.birth_year ? String(v.birth_year) : "",
    experience_years: v.experience_years !== null ? String(v.experience_years) : "",
    headline: v.headline ?? "",
    about: v.about ?? "",
    education: (v.education.length ? v.education : [{ institution: "", specialty: null, year: null }]).map((e: EducationItem) => ({
      institution: e.institution ?? "",
      specialty: e.specialty ?? "",
      year: e.year ? String(e.year) : "",
    })),
    approaches: Object.fromEntries(v.approaches.map((a) => [a.id, a.explanation ?? ""])),
    specializations: v.specializations,
    requests: v.requests,
    works_individual: p.formats.works_individual,
    works_pair: p.formats.works_pair,
    price_individual: kopecksToRubles(p.prices.price_individual),
    price_pair: kopecksToRubles(p.prices.price_pair),
  };
}

const toggle = (list: string[], id: string) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);

/** PRO-02: profile, photo, video card, approaches with explanations, requests, prices; pending changes banner (BR-PSY-05). */
export function ProfileForm({ initial, dictionaries }: Props) {
  const [profile, setProfile] = useState(initial);
  const [form, setForm] = useState<Form>(() => toForm(initial));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<{ tone: "success" | "info" | "danger"; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));

  const individualPrice = rublesToKopecks(form.price_individual);
  const category = categoryFor(individualPrice, dictionaries.price_categories);
  const approved = profile.qualification_status === "approved";

  function apply(next: OwnProfile, message?: { tone: "success" | "info" | "danger"; text: string }) {
    setProfile(next);
    setForm(toForm(next));
    if (message) setNotice(message);
  }

  async function save() {
    setSaving(true);
    setErrors({});
    setNotice(null);
    try {
      const body = {
        first_name: form.first_name,
        last_name: form.last_name,
        gender: form.gender || null,
        birth_year: form.birth_year ? Number(form.birth_year) : null,
        experience_years: form.experience_years ? Number(form.experience_years) : null,
        headline: form.headline,
        about: form.about,
        education: form.education
          .filter((e) => e.institution.trim())
          .map((e) => ({ institution: e.institution.trim(), specialty: e.specialty.trim() || null, year: e.year ? Number(e.year) : null })),
        approaches: Object.entries(form.approaches).map(([id, explanation]) => ({ id, explanation: explanation.trim() || null })),
        specializations: form.specializations,
        requests: form.requests,
        works_individual: form.works_individual,
        works_pair: form.works_pair,
        price_individual: rublesToKopecks(form.price_individual),
        price_pair: rublesToKopecks(form.price_pair),
      };
      const res = await api<{ data: OwnProfile; result: { applied: string[]; pending: string[] } }>("/pro/profile", { method: "PATCH", body });
      apply(res.data, {
        tone: res.result.pending.length ? "info" : "success",
        text: res.result.pending.length
          ? "Изменения отправлены на модерацию. Пока администратор их не проверит, на сайте видна прежняя версия профиля."
          : "Профиль сохранён.",
      });
    } catch (e) {
      if (e instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.errors)) flat[k] = v[0];
        setErrors(flat);
        setNotice({ tone: "danger", text: Object.keys(flat).length ? "Проверьте отмеченные поля." : e.message });
      } else {
        setNotice({ tone: "danger", text: "Не удалось связаться с сервером. Попробуйте ещё раз." });
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid max-w-4xl gap-6">
      <StatusPanel profile={profile} onChange={(p, text) => apply(p, { tone: "success", text })} />

      {profile.pending && (
        <Alert tone="warning" title="Изменения на модерации">
          На проверке: {profile.pending.labels.join(", ").toLowerCase()}. На сайте пока показывается прежняя версия — администратор проверит изменения в
          течение двух рабочих дней. В форме ниже — версия с вашими изменениями.
        </Alert>
      )}
      {!profile.pending && profile.last_review?.comment && (
        <Alert tone="info" title={`Комментарий модератора от ${dateTime(profile.last_review.reviewed_at, profile.timezone)}`}>
          {profile.last_review.comment}
        </Alert>
      )}
      {!approved && (
        <Alert tone="info">
          До подтверждения квалификации профиль не виден клиентам, поэтому изменения сохраняются сразу. После подтверждения правки публичных полей будут
          проходить модерацию.
        </Alert>
      )}
      {notice && (
        <Alert tone={notice.tone} className="sticky top-2 z-10">
          {notice.text}
        </Alert>
      )}

      <PhotoCard profile={profile} onChange={(p, text) => apply(p, { tone: text.includes("модерац") ? "info" : "success", text })} />

      <Card className="grid gap-4">
        <h2 className="text-lg font-semibold">Основное</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Имя" error={errors.first_name}>
            <Input value={form.first_name} onChange={(e) => set("first_name", e.target.value)} maxLength={80} required />
          </Field>
          <Field label="Фамилия" error={errors.last_name}>
            <Input value={form.last_name} onChange={(e) => set("last_name", e.target.value)} maxLength={80} required />
          </Field>
          <Field label="Пол" error={errors.gender}>
            <Select value={form.gender} onChange={(e) => set("gender", e.target.value as Form["gender"])}>
              <option value="">Не указан</option>
              <option value="female">Женский</option>
              <option value="male">Мужской</option>
            </Select>
          </Field>
          <Field label="Год рождения" hint="На сайте показывается возраст" error={errors.birth_year}>
            <Input inputMode="numeric" value={form.birth_year} onChange={(e) => set("birth_year", e.target.value.replace(/\D/g, "").slice(0, 4))} />
          </Field>
          <Field label="Опыт практики, лет" error={errors.experience_years}>
            <Input inputMode="numeric" value={form.experience_years} onChange={(e) => set("experience_years", e.target.value.replace(/\D/g, "").slice(0, 2))} />
          </Field>
        </div>
        <Field label="Коротко о себе" hint="Одна-две фразы для карточки в каталоге" error={errors.headline}>
          <Input value={form.headline} onChange={(e) => set("headline", e.target.value)} maxLength={200} />
        </Field>
        <Field label="О себе" hint={`Как вы работаете, чем можете помочь. ${form.about.trim().length} / не меньше 100 символов`} error={errors.about}>
          <Textarea value={form.about} onChange={(e) => set("about", e.target.value)} rows={8} maxLength={5000} />
        </Field>
      </Card>

      <Card className="grid gap-4">
        <h2 className="text-lg font-semibold">Образование</h2>
        {form.education.map((e, i) => (
          <div key={i} className="grid gap-3 rounded-xl bg-ground p-4 sm:grid-cols-[2fr_2fr_1fr_auto] sm:items-end">
            <Field label="Учебное заведение" error={errors[`education.${i}.institution`]}>
              <Input value={e.institution} onChange={(ev) => set("education", form.education.map((x, j) => (j === i ? { ...x, institution: ev.target.value } : x)))} />
            </Field>
            <Field label="Специальность">
              <Input value={e.specialty} onChange={(ev) => set("education", form.education.map((x, j) => (j === i ? { ...x, specialty: ev.target.value } : x)))} />
            </Field>
            <Field label="Год окончания" error={errors[`education.${i}.year`]}>
              <Input inputMode="numeric" value={e.year} onChange={(ev) => set("education", form.education.map((x, j) => (j === i ? { ...x, year: ev.target.value.replace(/\D/g, "").slice(0, 4) } : x)))} />
            </Field>
            <Button type="button" variant="ghost" size="sm" onClick={() => set("education", form.education.filter((_, j) => j !== i))} aria-label="Удалить">
              Удалить
            </Button>
          </div>
        ))}
        <div>
          <Button type="button" variant="secondary" size="sm" onClick={() => set("education", [...form.education, { institution: "", specialty: "", year: "" }])}>
            Добавить учебное заведение
          </Button>
        </div>
        <p className="text-sm text-muted">
          Дипломы загружаются в разделе <Link href="/pro/qualification" className="text-brand hover:underline">«Квалификация»</Link> — на сайте показываются только названия проверенных документов.
        </p>
      </Card>

      <Card className="grid gap-4">
        <div>
          <h2 className="text-lg font-semibold">Подходы с пояснениями</h2>
          <p className="text-sm text-muted">Отметьте подходы и коротко поясните, как вы применяете каждый из них. Пояснение увидят клиенты.</p>
        </div>
        {errors.approaches && <p className="text-sm text-danger">{errors.approaches}</p>}
        <ul className="grid gap-3">
          {dictionaries.approaches.map((a) => {
            const checked = a.id in form.approaches;
            return (
              <li key={a.id} className="rounded-xl border border-line p-4">
                <Checkbox
                  checked={checked}
                  onChange={() =>
                    set(
                      "approaches",
                      checked ? Object.fromEntries(Object.entries(form.approaches).filter(([id]) => id !== a.id)) : { ...form.approaches, [a.id]: "" },
                    )
                  }
                  label={
                    <span>
                      <span className="font-medium text-ink">{a.title}</span>
                      {a.explanation && <span className="block text-sm text-muted">{a.explanation}</span>}
                    </span>
                  }
                />
                {checked && (
                  <Textarea
                    className="mt-3 min-h-20"
                    placeholder="Как вы применяете этот подход в работе"
                    value={form.approaches[a.id]}
                    maxLength={1000}
                    onChange={(e) => set("approaches", { ...form.approaches, [a.id]: e.target.value })}
                  />
                )}
              </li>
            );
          })}
        </ul>
      </Card>

      <Card className="grid gap-4">
        <h2 className="text-lg font-semibold">Специализации</h2>
        <div className="flex flex-wrap gap-2">
          {dictionaries.specializations.map((s) => (
            <Chip key={s.id} active={form.specializations.includes(s.id)} onClick={() => set("specializations", toggle(form.specializations, s.id))}>
              {s.title}
            </Chip>
          ))}
        </div>
      </Card>

      <Card className="grid gap-4">
        <div>
          <h2 className="text-lg font-semibold">Запросы</h2>
          <p className="text-sm text-muted">С какими запросами вы работаете. По ним клиенты находят вас в каталоге и на страницах «С чем помогаем». Выбрано: {form.requests.length}.</p>
        </div>
        {errors.requests && <p className="text-sm text-danger">{errors.requests}</p>}
        {dictionaries.request_groups.map((g) => (
          <fieldset key={g.id} className="grid gap-2">
            <legend className="mb-1 flex flex-wrap items-center gap-2 text-sm font-medium text-ink-2">
              {g.title}
              <button
                type="button"
                className="text-xs font-normal text-brand hover:underline"
                onClick={() => {
                  const ids = g.requests.map((r) => r.id);
                  const all = ids.every((id) => form.requests.includes(id));
                  set("requests", all ? form.requests.filter((id) => !ids.includes(id)) : [...new Set([...form.requests, ...ids])]);
                }}
              >
                {g.requests.every((r) => form.requests.includes(r.id)) ? "снять все" : "выбрать все"}
              </button>
            </legend>
            <div className="flex flex-wrap gap-2">
              {g.requests.map((r) => (
                <Chip key={r.id} active={form.requests.includes(r.id)} onClick={() => set("requests", toggle(form.requests, r.id))}>
                  {r.title}
                </Chip>
              ))}
            </div>
          </fieldset>
        ))}
      </Card>

      <Card className="grid gap-4">
        <div>
          <h2 className="text-lg font-semibold">Форматы и стоимость</h2>
          <p className="text-sm text-muted">Цену вы назначаете сами, она меняется сразу и не затрагивает уже назначенные сессии. Комиссия платформы — 30 %.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-3 rounded-xl bg-ground p-4">
            <Checkbox checked={form.works_individual} onChange={(e) => set("works_individual", e.target.checked)} label="Индивидуальные сессии, 50 мин" />
            <Field label="Цена, ₽" error={errors.price_individual ?? errors.works_individual}>
              <Input inputMode="decimal" value={form.price_individual} disabled={!form.works_individual} onChange={(e) => set("price_individual", e.target.value)} />
            </Field>
            <p className="text-sm text-muted">
              {category ? (
                <>
                  Категория: <span className="font-medium text-ink-2">{category.title}</span>
                </>
              ) : (
                "Категория определяется ценой индивидуальной сессии"
              )}
            </p>
          </div>
          <div className="grid content-start gap-3 rounded-xl bg-ground p-4">
            <Checkbox checked={form.works_pair} onChange={(e) => set("works_pair", e.target.checked)} label="Парные сессии, 90 мин" />
            <Field label="Цена, ₽" error={errors.price_pair}>
              <Input inputMode="decimal" value={form.price_pair} disabled={!form.works_pair} onChange={(e) => set("price_pair", e.target.value)} />
            </Field>
          </div>
        </div>
        {profile.price_history.length > 1 && (
          <details className="text-sm">
            <summary className="cursor-pointer text-muted">История цен</summary>
            <ul className="mt-2 grid gap-1 text-ink-2">
              {profile.price_history.map((h, i) => (
                <li key={i}>
                  {dateTime(h.created_at, profile.timezone)}: индивидуальная {rub(h.price_individual)}
                  {h.price_pair ? `, парная ${rub(h.price_pair)}` : ""}
                </li>
              ))}
            </ul>
          </details>
        )}
      </Card>

      <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-ground/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
        <Button onClick={save} loading={saving}>
          {approved ? "Сохранить и отправить на модерацию" : "Сохранить"}
        </Button>
        <Button variant="ghost" onClick={() => setForm(toForm(profile))} disabled={saving}>
          Отменить правки
        </Button>
      </div>

      <VideoCard profile={profile} onChange={(p, text) => apply(p, { tone: "info", text })} />
    </div>
  );
}

function StatusPanel({ profile, onChange }: { profile: OwnProfile; onChange: (p: OwnProfile, text: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const q = QUALIFICATION[profile.qualification_status];
  const w = WORK[profile.work_status];

  async function toggleWork() {
    setBusy(true);
    setError(null);
    try {
      const pausing = profile.work_status === "active";
      const res = await api<{ data: OwnProfile }>(pausing ? "/pro/profile/pause" : "/pro/profile/resume", { method: "POST" });
      onChange(res.data, pausing ? "Приём новых записей приостановлен. Текущие клиенты могут записываться как обычно." : "Приём новых записей снова открыт.");
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Не удалось изменить статус.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted">Квалификация:</span>
            <Badge tone={q.tone}>{q.label}</Badge>
            {profile.qualification_status !== "approved" && (
              <Link href="/pro/qualification" className="text-sm text-brand hover:underline">
                Перейти к проверке
              </Link>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted">Новые записи:</span>
            <Badge tone={w.tone}>{w.label}</Badge>
            {profile.work_status_reason && <span className="text-sm text-muted">— {profile.work_status_reason}</span>}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted">Профиль на сайте:</span>
            {profile.is_published ? (
              <Link href={profile.public_path} className="text-sm font-medium text-brand hover:underline" target="_blank">
                {profile.is_bookable ? "опубликован — открыть" : "опубликован, но запись закрыта — открыть"}
              </Link>
            ) : (
              <span className="text-sm text-ink-2">
                {profile.qualification_status === "approved" ? "появится после добавления рабочих интервалов в графике" : "появится после подтверждения квалификации"}
              </span>
            )}
          </div>
        </div>
        {profile.work_status !== "blocked" && (
          <Button variant="secondary" size="sm" onClick={toggleWork} loading={busy}>
            {profile.work_status === "active" ? "Приостановить приём" : "Возобновить приём"}
          </Button>
        )}
      </div>
      {profile.missing.length > 0 && (
        <div className="rounded-xl bg-warning-soft px-4 py-3 text-sm">
          <p className="font-medium">Чтобы профиль можно было отправить на проверку и опубликовать, заполните:</p>
          <ul className="mt-1 list-disc pl-5 text-ink-2">
            {profile.missing.map((m) => (
              <li key={m.code}>
                {m.label} — {m.hint}
              </li>
            ))}
          </ul>
        </div>
      )}
      {error && <p className="text-sm text-danger">{error}</p>}
    </Card>
  );
}

function PhotoCard({ profile, onChange }: { profile: OwnProfile; onChange: (p: OwnProfile, text: string) => void }) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const pendingPhoto = profile.pending?.fields.includes("photo_file_id");

  async function upload(file: File) {
    setBusy(true);
    setError(null);
    const body = new FormData();
    body.append("file", file);
    try {
      const res = await api<{ data: OwnProfile; result: { pending: string[] } }>("/pro/profile/photo", { method: "POST", body });
      onChange(res.data, res.result.pending.includes("photo_file_id") ? "Новое фото отправлено на модерацию." : "Фото обновлено.");
    } catch (e) {
      setError(e instanceof ApiError ? (e.field("file") ?? e.message) : "Не удалось загрузить фото.");
    } finally {
      setBusy(false);
      if (input.current) input.current.value = "";
    }
  }

  async function remove() {
    setBusy(true);
    try {
      const res = await api<{ data: OwnProfile; result: { pending: string[] } }>("/pro/profile/photo", { method: "DELETE" });
      onChange(res.data, res.result.pending.length ? "Удаление фото отправлено на модерацию." : "Фото удалено.");
    } catch {
      setError("Не удалось удалить фото.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="flex flex-col gap-5 sm:flex-row sm:items-center">
      <Avatar photoUrl={profile.photo_url} firstName={profile.values.first_name} lastName={profile.values.last_name} size={112} />
      <div className="grid gap-2">
        <h2 className="text-lg font-semibold">Фото</h2>
        <p className="text-sm text-muted">Портрет на светлом фоне, лицо хорошо видно. JPG, PNG или WebP до 10 МБ.</p>
        {pendingPhoto && <Badge tone="warning">Новое фото на модерации</Badge>}
        <div className="flex flex-wrap gap-2">
          <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
          <Button variant="secondary" size="sm" loading={busy} onClick={() => input.current?.click()}>
            {profile.photo_url ? "Заменить фото" : "Загрузить фото"}
          </Button>
          {profile.photo_url && (
            <Button variant="ghost" size="sm" disabled={busy} onClick={remove}>
              Удалить
            </Button>
          )}
        </div>
        {error && <p className="text-sm text-danger">{error}</p>}
      </div>
    </Card>
  );
}

function readDuration(file: File): Promise<number | null> {
  return new Promise((resolve) => {
    const video = document.createElement("video");
    video.preload = "metadata";
    const url = URL.createObjectURL(file);
    const done = (value: number | null) => {
      URL.revokeObjectURL(url);
      resolve(value);
    };
    video.onloadedmetadata = () => done(Number.isFinite(video.duration) ? Math.ceil(video.duration) : null);
    video.onerror = () => done(null);
    video.src = url;
  });
}

function VideoCard({ profile, onChange }: { profile: OwnProfile; onChange: (p: OwnProfile, text: string) => void }) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { seconds, megabytes } = profile.video_limits;
  const v = profile.video;
  const status = VIDEO[v.status];

  async function upload(file: File) {
    setError(null);
    if (file.size > megabytes * 1024 * 1024) {
      setError(`Файл больше ${megabytes} МБ.`);
      return;
    }
    const duration = await readDuration(file);
    if (duration !== null && duration > seconds) {
      setError(`Видео длится ${duration} с — должно быть не дольше ${seconds} с.`);
      return;
    }
    setBusy(true);
    const body = new FormData();
    body.append("file", file);
    if (duration !== null) body.append("duration", String(duration));
    try {
      const res = await api<{ data: OwnProfile }>("/pro/profile/video", { method: "POST", body });
      onChange(res.data, "Видеовизитка отправлена на модерацию. Она не влияет на проверку квалификации.");
    } catch (e) {
      setError(e instanceof ApiError ? (e.field("file") ?? e.field("duration") ?? e.message) : "Не удалось загрузить видео.");
    } finally {
      setBusy(false);
      if (input.current) input.current.value = "";
    }
  }

  async function remove() {
    setBusy(true);
    try {
      const res = await api<{ data: OwnProfile }>("/pro/profile/video", { method: "DELETE" });
      onChange(res.data, "Видеовизитка удалена.");
    } catch {
      setError("Не удалось удалить видео.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Видеовизитка</h2>
          <p className="text-sm text-muted">
            Необязательно. До {seconds} секунд и {megabytes} МБ, MP4 или WebM. Расскажите, как проходит первая встреча и с чем вы работаете. Видео проходит
            отдельную модерацию.
          </p>
        </div>
        <Badge tone={status.tone}>{status.label}</Badge>
      </div>
      {v.status === "rejected" && v.comment && (
        <Alert tone="danger" title="Видеовизитка отклонена">
          {v.comment}
        </Alert>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        {v.url && v.status !== "approved" && (
          <VideoPreview title={v.status === "pending" ? "На модерации" : "Последняя загрузка"} url={v.url} />
        )}
        {v.approved_url && <VideoPreview title="На сайте сейчас" url={v.approved_url} />}
      </div>
      <div className="flex flex-wrap gap-2">
        <input ref={input} type="file" accept="video/mp4,video/webm,video/quicktime" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
        <Button variant="secondary" size="sm" loading={busy} onClick={() => input.current?.click()}>
          {v.status === "none" ? "Загрузить видео" : "Загрузить новое видео"}
        </Button>
        {v.status !== "none" && (
          <Button variant="ghost" size="sm" disabled={busy} onClick={remove}>
            Удалить видеовизитку
          </Button>
        )}
      </div>
      {error && <p className="text-sm text-danger">{error}</p>}
    </Card>
  );
}

function VideoPreview({ title, url }: { title: ReactNode; url: string }) {
  return (
    <figure className="grid gap-2">
      <video src={url} controls preload="metadata" playsInline className="aspect-video w-full rounded-xl bg-graphite" />
      <figcaption className="text-sm text-muted">{title}</figcaption>
    </figure>
  );
}
