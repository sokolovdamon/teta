"use client";

import { useEffect, useMemo, useState } from "react";
import { Alert, Badge, Button, Card, Chip, EmptyState, Field, Input, Select } from "@/components/ui";
import { dayKey, groupSlotsByDay, timezoneLabel } from "@/features/catalog/slots";
import { ApiError, api } from "@/lib/api";
import { dateTime } from "@/lib/format";
import { TIMEZONES, WEEKDAYS } from "./labels";
import { validateIntervals, zonedToIso } from "./schedule";
import type { ScheduleException, ScheduleInterval, ScheduleOverview } from "./types";

type Row = ScheduleInterval & { key: string };

let seq = 0;
const withKeys = (list: ScheduleInterval[]): Row[] => list.map((i) => ({ ...i, key: i.id ?? `new-${++seq}` }));

/** PRO-03: weekly intervals, vacations and blocked dates, timezone and personal limits, preview of free slots. */
export function ScheduleEditor({ initial }: { initial: ScheduleOverview }) {
  const [data, setData] = useState(initial);
  const [rows, setRows] = useState<Row[]>(() => withKeys(initial.intervals));
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [previewVersion, setPreviewVersion] = useState(0);

  const minLength = Math.min(
    ...[data.formats.works_individual || !data.formats.works_pair ? data.platform.duration_individual : Infinity, data.formats.works_pair ? data.platform.duration_pair : Infinity],
  );
  const errors = validateIntervals(rows, minLength);
  const hasErrors = Object.keys(errors).length > 0;

  function update(next: ScheduleOverview, text?: string) {
    setData(next);
    setRows(withKeys(next.intervals));
    setDirty(false);
    setPreviewVersion((v) => v + 1);
    if (text) setNotice({ tone: "success", text });
  }

  function edit(key: string, patch: Partial<ScheduleInterval>) {
    setRows((r) => r.map((x) => (x.key === key ? { ...x, ...patch } : x)));
    setDirty(true);
  }

  async function saveIntervals() {
    setSaving(true);
    setNotice(null);
    try {
      const res = await api<{ data: ScheduleOverview }>("/pro/schedule/intervals", {
        method: "PUT",
        body: { intervals: rows.map(({ weekday, starts_at, ends_at }) => ({ weekday, starts_at, ends_at })) },
      });
      update(res.data, "График сохранён.");
    } catch (e) {
      setNotice({ tone: "danger", text: e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось сохранить график." });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid max-w-5xl gap-6">
      {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}

      <Card className="grid gap-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold">Рабочие интервалы</h2>
            <p className="text-sm text-muted">
              Время в вашем часовом поясе ({timezoneLabel(data.timezone)}). Промежутки между интервалами — перерывы. Слоты идут от начала интервала: сессия{" "}
              {data.platform.duration_individual} мин{data.formats.works_pair ? ` (парная — ${data.platform.duration_pair} мин)` : ""} и перерыв{" "}
              {data.effective.buffer_minutes} мин.
            </p>
          </div>
          {rows.length === 0 && <Badge tone="warning">Без интервалов профиль не публикуется</Badge>}
        </div>

        <div className="grid gap-3">
          {WEEKDAYS.map((name, i) => {
            const weekday = i + 1;
            const dayRows = rows.filter((r) => r.weekday === weekday);
            return (
              <div key={weekday} className="grid gap-2 rounded-xl bg-ground p-3 sm:grid-cols-[150px_1fr] sm:items-start">
                <p className="pt-2 font-medium">{name}</p>
                <div className="grid gap-2">
                  {dayRows.length === 0 && <p className="pt-2 text-sm text-muted">Выходной</p>}
                  {dayRows.map((r) => {
                    const idx = rows.indexOf(r);
                    return (
                      <div key={r.key} className="flex flex-wrap items-center gap-2">
                        <Input type="time" step={300} className="h-10 w-32" value={r.starts_at} onChange={(e) => edit(r.key, { starts_at: e.target.value })} aria-label={`${name}: начало`} />
                        <span className="text-muted">—</span>
                        <Input
                          type="time"
                          step={300}
                          className="h-10 w-32"
                          value={r.ends_at === "24:00" ? "23:59" : r.ends_at}
                          onChange={(e) => edit(r.key, { ends_at: e.target.value === "23:59" ? "24:00" : e.target.value })}
                          aria-label={`${name}: конец`}
                        />
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => {
                            setRows((all) => all.filter((x) => x.key !== r.key));
                            setDirty(true);
                          }}
                        >
                          Удалить
                        </Button>
                        {errors[idx] && <span className="w-full text-sm text-danger">{errors[idx]}</span>}
                      </div>
                    );
                  })}
                  <div className="flex flex-wrap gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => {
                        const last = dayRows[dayRows.length - 1];
                        const start = last ? last.ends_at : "10:00";
                        setRows((all) => [...all, { key: `new-${++seq}`, weekday, starts_at: start === "24:00" ? "20:00" : start, ends_at: last ? "22:00" : "14:00" }]);
                        setDirty(true);
                      }}
                    >
                      Добавить интервал
                    </Button>
                    {weekday > 1 && rows.some((r) => r.weekday === weekday - 1) && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          const copy = rows.filter((r) => r.weekday === weekday - 1).map((r) => ({ ...r, weekday, id: undefined, key: `new-${++seq}` }));
                          setRows((all) => [...all.filter((x) => x.weekday !== weekday), ...copy]);
                          setDirty(true);
                        }}
                      >
                        Как в {WEEKDAYS[weekday - 2].toLowerCase()}
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button onClick={saveIntervals} loading={saving} disabled={!dirty || hasErrors}>
            Сохранить график
          </Button>
          {dirty && (
            <Button variant="ghost" onClick={() => update(data)}>
              Отменить изменения
            </Button>
          )}
          {hasErrors && <span className="text-sm text-danger">Исправьте интервалы с ошибками</span>}
        </div>
      </Card>

      <ExceptionsCard data={data} onChange={(d, text) => update(d, text)} />
      <SettingsCard data={data} onChange={(d, text) => update(d, text)} />
      <PreviewCard data={data} version={previewVersion} />
    </div>
  );
}

function ExceptionsCard({ data, onChange }: { data: ScheduleOverview; onChange: (d: ScheduleOverview, text: string) => void }) {
  const tz = data.timezone;
  const today = dayKey(new Date(), tz);
  const [kind, setKind] = useState<"vacation" | "blocked">("vacation");
  const [from, setFrom] = useState(today);
  const [to, setTo] = useState(today);
  const [comment, setComment] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ text: string; sessions?: { id: string; starts_at: string }[] } | null>(null);

  async function add(e: React.FormEvent) {
    e.preventDefault();
    if (to < from) {
      setError({ text: "Дата окончания раньше даты начала." });
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const end = new Date(Date.parse(`${to}T00:00:00Z`) + 86_400_000).toISOString().slice(0, 10);
      await api("/pro/schedule/exceptions", {
        method: "POST",
        body: { kind, starts_at: zonedToIso(from, "00:00", tz), ends_at: zonedToIso(end, "00:00", tz), comment: comment || null },
      });
      const res = await api<{ data: ScheduleOverview }>("/pro/schedule");
      setComment("");
      onChange(res.data, kind === "vacation" ? "Отпуск добавлен." : "Даты заблокированы.");
    } catch (err) {
      if (err instanceof ApiError) {
        const payload = err.payload as { sessions?: { id: string; starts_at: string }[] } | null;
        setError({ text: Object.values(err.errors)[0]?.[0] ?? err.message, sessions: payload?.sessions });
      } else setError({ text: "Не удалось сохранить период." });
    } finally {
      setBusy(false);
    }
  }

  async function remove(ex: ScheduleException) {
    try {
      const res = await api<{ data: ScheduleOverview }>(`/pro/schedule/exceptions/${ex.id}`, { method: "DELETE" });
      onChange(res.data, "Период удалён.");
    } catch {
      setError({ text: "Не удалось удалить период." });
    }
  }

  return (
    <Card className="grid gap-4">
      <div>
        <h2 className="text-lg font-semibold">Отпуска и блокировка дат</h2>
        <p className="text-sm text-muted">В эти дни запись закрыта. Если на период уже есть записи, сначала перенесите или отмените их в календаре.</p>
      </div>
      {data.exceptions.length === 0 ? (
        <p className="text-sm text-muted">Отпусков и заблокированных дат нет.</p>
      ) : (
        <ul className="grid gap-2">
          {data.exceptions.map((ex) => (
            <li key={ex.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-3">
              <span>
                <Badge tone={ex.kind === "vacation" ? "brand" : "neutral"}>{ex.kind === "vacation" ? "Отпуск" : "Заблокировано"}</Badge>{" "}
                <span className="text-ink-2">
                  {dateTime(ex.starts_at, tz)} — {dateTime(ex.ends_at, tz)}
                </span>
                {ex.comment && <span className="text-sm text-muted"> · {ex.comment}</span>}
              </span>
              <Button variant="ghost" size="sm" onClick={() => remove(ex)}>
                Удалить
              </Button>
            </li>
          ))}
        </ul>
      )}
      <form onSubmit={add} className="grid gap-3 rounded-xl bg-ground p-4 sm:grid-cols-[1fr_1fr_1fr] sm:items-end">
        <Field label="Что">
          <Select value={kind} onChange={(e) => setKind(e.target.value as "vacation" | "blocked")}>
            <option value="vacation">Отпуск</option>
            <option value="blocked">Заблокировать даты</option>
          </Select>
        </Field>
        <Field label="С (включительно)">
          <Input type="date" min={today} value={from} onChange={(e) => setFrom(e.target.value)} required />
        </Field>
        <Field label="По (включительно)">
          <Input type="date" min={from} value={to} onChange={(e) => setTo(e.target.value)} required />
        </Field>
        <Field label="Комментарий для себя" className="sm:col-span-2">
          <Input value={comment} onChange={(e) => setComment(e.target.value)} maxLength={255} />
        </Field>
        <Button type="submit" variant="secondary" loading={busy}>
          Добавить
        </Button>
      </form>
      {error && (
        <Alert tone="danger">
          {error.text}
          {error.sessions && error.sessions.length > 0 && (
            <ul className="mt-1 list-disc pl-5">
              {error.sessions.map((s) => (
                <li key={s.id}>{dateTime(s.starts_at, tz)}</li>
              ))}
            </ul>
          )}
        </Alert>
      )}
    </Card>
  );
}

function SettingsCard({ data, onChange }: { data: ScheduleOverview; onChange: (d: ScheduleOverview, text: string) => void }) {
  const [timezone, setTimezone] = useState(data.timezone);
  const [lead, setLead] = useState(data.settings.min_lead_minutes ? String(data.settings.min_lead_minutes / 60) : "");
  const [horizon, setHorizon] = useState(data.settings.horizon_days ? String(data.settings.horizon_days) : "");
  const [buffer, setBuffer] = useState(data.settings.buffer_minutes ? String(data.settings.buffer_minutes) : "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const p = data.platform;

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const res = await api<{ data: ScheduleOverview }>("/pro/schedule/settings", {
        method: "PATCH",
        body: {
          timezone,
          min_lead_minutes: lead ? Math.round(Number(lead.replace(",", ".")) * 60) : null,
          horizon_days: horizon ? Number(horizon) : null,
          buffer_minutes: buffer ? Number(buffer) : null,
        },
      });
      onChange(res.data, "Настройки записи сохранены.");
    } catch (err) {
      if (err instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.errors)) flat[k] = v[0];
        setErrors(Object.keys(flat).length ? flat : { timezone: err.message });
      } else setErrors({ timezone: "Не удалось сохранить настройки." });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="grid gap-4">
      <div>
        <h2 className="text-lg font-semibold">Часовой пояс и правила записи</h2>
        <p className="text-sm text-muted">Свои ограничения можно сделать только строже, чем у платформы. Пустое поле — правило платформы.</p>
      </div>
      <form onSubmit={save} className="grid gap-4 sm:grid-cols-2">
        <Field label="Часовой пояс графика" error={errors.timezone}>
          <Select value={timezone} onChange={(e) => setTimezone(e.target.value)}>
            {[...new Set([timezone, ...TIMEZONES])].map((tz) => (
              <option key={tz} value={tz}>
                {timezoneLabel(tz)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Не раньше чем за, часов" hint={`Платформа: ${p.min_lead_minutes / 60} ч`} error={errors.min_lead_minutes}>
          <Input inputMode="decimal" value={lead} placeholder={String(p.min_lead_minutes / 60)} onChange={(e) => setLead(e.target.value)} />
        </Field>
        <Field label="Открывать запись на, дней вперёд" hint={`Платформа: до ${p.horizon_days} дн.`} error={errors.horizon_days}>
          <Input inputMode="numeric" value={horizon} placeholder={String(p.horizon_days)} onChange={(e) => setHorizon(e.target.value.replace(/\D/g, ""))} />
        </Field>
        <Field label="Перерыв между сессиями, мин" hint={`Платформа: ${p.buffer_minutes} мин`} error={errors.buffer_minutes}>
          <Input inputMode="numeric" value={buffer} placeholder={String(p.buffer_minutes)} onChange={(e) => setBuffer(e.target.value.replace(/\D/g, ""))} />
        </Field>
        <div className="sm:col-span-2">
          <Button type="submit" variant="secondary" loading={busy}>
            Сохранить настройки
          </Button>
        </div>
      </form>
    </Card>
  );
}

function PreviewCard({ data, version }: { data: ScheduleOverview; version: number }) {
  const formats = [data.formats.works_individual ? "individual" : null, data.formats.works_pair ? "pair" : null].filter(Boolean) as ("individual" | "pair")[];
  const [format, setFormat] = useState<"individual" | "pair">(formats[0] ?? "individual");
  const [state, setState] = useState<{ status: "loading" | "error" } | { status: "ok"; slots: string[] }>({ status: "loading" });

  useEffect(() => {
    const controller = new AbortController();
    api<{ data: { slots: string[] } }>("/pro/schedule/preview", { query: { format, days: 14 }, signal: controller.signal })
      .then((r) => setState({ status: "ok", slots: r.data.slots }))
      .catch((e: unknown) => {
        if ((e as { name?: string })?.name !== "AbortError") setState({ status: "error" });
      });
    return () => controller.abort();
  }, [format, version]);

  const groups = useMemo(() => (state.status === "ok" ? groupSlotsByDay(state.slots, data.timezone) : []), [state, data.timezone]);

  return (
    <Card className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Так клиенты увидят свободное время</h2>
          <p className="text-sm text-muted">Ближайшие 14 дней с учётом записей, отпусков и правил записи. Время — в вашем часовом поясе.</p>
        </div>
        {formats.length > 1 && (
          <div className="flex gap-2">
            {formats.map((f) => (
              <Chip key={f} active={f === format} onClick={() => setFormat(f)}>
                {f === "pair" ? "Парная" : "Индивидуальная"}
              </Chip>
            ))}
          </div>
        )}
      </div>
      {state.status === "loading" && <p className="text-muted">Загрузка…</p>}
      {state.status === "error" && <Alert tone="danger">Не удалось загрузить свободное время.</Alert>}
      {state.status === "ok" && groups.length === 0 && (
        <EmptyState title="Свободного времени нет" description="Добавьте рабочие интервалы или проверьте отпуска и правила записи." />
      )}
      {groups.length > 0 && (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {groups.map((g) => (
            <li key={g.key} className="rounded-xl bg-ground p-3">
              <p className="mb-2 text-sm">
                <span className="font-semibold capitalize">{g.weekday}</span> <span className="text-muted">{g.date}</span>
              </p>
              <div className="flex flex-wrap gap-1.5">
                {g.slots.map((s) => (
                  <span key={s.iso} className="rounded-lg bg-surface px-2 py-1 text-sm num ring-1 ring-line">
                    {s.time}
                  </span>
                ))}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
