"use client";

import { useMemo, useState } from "react";
import { Alert, Button, Card, Checkbox, Field, Input, Select, Textarea } from "@/components/ui";
import { api } from "@/lib/api";
import { useForm } from "@/lib/useForm";
import { TYPE_OPTIONS, emptyForm, toPayload, type PromoFormValues } from "./form";
import type { Lookups, PromoKind, PromoType, ServiceType } from "./types";

type Mode = "create" | "edit" | "batch";
type Values = PromoFormValues & { size: string; prefix: string };

type Props = {
  mode: Mode;
  initial?: PromoFormValues;
  /** Fields the API lets change for this code (edit mode); others are shown read-only. */
  editable?: string[];
  lookups: Lookups | null;
  onSubmit: (payload: Record<string, unknown>) => Promise<void>;
  onCancel: () => void;
};

/** ADM-09 form: type and value, mass or individual, period, limits, minimum amount, restrictions by services, psychologists, price categories and client segments. */
export function PromoForm({ mode, initial, editable, lookups, onSubmit, onCancel }: Props) {
  const form = useForm<Values>({ ...(initial ?? emptyForm()), size: "100", prefix: "" });
  const v = form.values;
  const [psyFilter, setPsyFilter] = useState("");
  const [ownerQuery, setOwnerQuery] = useState("");
  const [owners, setOwners] = useState<Lookups["users"]>([]);

  const locked = (field: string) => mode === "edit" && !!editable && !editable.includes(field);
  const restrictionsLocked = locked("restrictions");
  const psychologists = useMemo(
    () => (lookups?.psychologists ?? []).filter((p) => p.name.toLowerCase().includes(psyFilter.trim().toLowerCase())),
    [lookups, psyFilter],
  );

  function toggle<K extends "service_types" | "psychologist_ids" | "price_category_ids">(key: K, id: Values[K][number]) {
    const list = v[key] as string[];
    form.set(key, (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]) as Values[K]);
  }

  async function searchOwners() {
    if (ownerQuery.trim().length < 2) return;
    const res = await api<{ data: Lookups }>("/admin/promo/lookups", { query: { q: ownerQuery.trim() } }).catch(() => null);
    setOwners(res?.data.users ?? []);
  }

  const valueIsMoney = v.type === "fixed";
  const err = (key: string) => form.errors[key] ?? null;

  return (
    <Card>
      <form
        className="grid gap-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.submit(async (values) => {
            const payload = toPayload(values);
            if (mode === "batch") {
              await onSubmit({ ...payload, size: Number.parseInt(values.size, 10) || 0, prefix: values.prefix.trim() || null, code: undefined, kind: undefined, owner_user_id: undefined });
            } else {
              await onSubmit(payload);
            }
          });
        }}
      >
        <h2 className="text-lg font-semibold">{mode === "create" ? "Новый промокод" : mode === "batch" ? "Пакет индивидуальных кодов" : `Промокод ${v.code}`}</h2>
        {form.message && <Alert tone="danger">{form.message}</Alert>}
        {mode === "edit" && editable && !editable.includes("type") && (
          <Alert tone="info">Промокод опубликован: тип, размер скидки, период начала и ограничения не меняются, чтобы не ухудшить условия уже выданных кодов.</Alert>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          {mode === "batch" ? (
            <>
              <Field label="Количество кодов" error={err("size")} hint="От 1 до 5 000 уникальных кодов">
                <Input type="number" min={1} max={5000} required value={v.size} onChange={(e) => form.set("size", e.target.value)} />
              </Field>
              <Field label="Префикс (необязательно)" error={err("prefix")} hint="Латиница и цифры, например GIFT">
                <Input value={v.prefix} maxLength={10} onChange={(e) => form.set("prefix", e.target.value)} />
              </Field>
            </>
          ) : (
            <Field label="Код" error={err("code")} hint={mode === "create" ? "Оставьте пустым — код сгенерируется" : undefined}>
              <Input value={v.code} disabled={locked("code")} maxLength={32} onChange={(e) => form.set("code", e.target.value.toUpperCase())} placeholder="AUTUMN20" />
            </Field>
          )}
          <Field label={mode === "batch" ? "Название пакета" : "Название"} error={err("title")}>
            <Input value={v.title} required={mode === "batch"} disabled={locked("title")} onChange={(e) => form.set("title", e.target.value)} />
          </Field>
        </div>

        <Field label="Условия акции" hint="Публикуются до старта акции" error={err("description")}>
          <Textarea value={v.description} disabled={locked("description")} onChange={(e) => form.set("description", e.target.value)} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Тип" error={err("type")} hint={TYPE_OPTIONS.find((t) => t.value === v.type)?.hint}>
            <Select value={v.type} disabled={locked("type")} onChange={(e) => form.set("type", e.target.value as PromoType)}>
              {TYPE_OPTIONS.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={valueIsMoney ? "Скидка, ₽" : "Скидка, %"} error={err("value")}>
            <Input
              inputMode="decimal"
              required
              disabled={locked("value")}
              value={v.value}
              onChange={(e) => form.set("value", e.target.value)}
              placeholder={valueIsMoney ? "500" : "20"}
            />
          </Field>
          {mode !== "batch" && (
            <Field label="Вид" error={err("kind")} hint={v.kind === "mass" ? "Один код для многих клиентов" : "Код для одного клиента"}>
              <Select value={v.kind} disabled={locked("kind")} onChange={(e) => form.set("kind", e.target.value as PromoKind)}>
                <option value="mass">Массовый</option>
                <option value="individual">Индивидуальный</option>
              </Select>
            </Field>
          )}
        </div>

        {mode !== "batch" && v.kind === "individual" && (
          <div className="grid gap-2">
            <p className="text-sm font-medium text-ink-2">Владелец кода (необязательно)</p>
            {v.owner_user_id ? (
              <div className="flex flex-wrap items-center gap-3">
                <span>{v.owner_label || v.owner_user_id}</span>
                {!locked("owner_user_id") && (
                  <Button type="button" variant="ghost" size="sm" onClick={() => form.set("owner_user_id", "")}>
                    Убрать
                  </Button>
                )}
              </div>
            ) : (
              <div className="flex flex-wrap items-end gap-2">
                <Input className="max-w-xs" value={ownerQuery} disabled={locked("owner_user_id")} onChange={(e) => setOwnerQuery(e.target.value)} placeholder="Email или имя клиента" />
                <Button type="button" variant="secondary" onClick={searchOwners} disabled={locked("owner_user_id")}>
                  Найти
                </Button>
              </div>
            )}
            {!v.owner_user_id && owners.length > 0 && (
              <ul className="grid gap-1">
                {owners.map((u) => (
                  <li key={u.id}>
                    <button
                      type="button"
                      className="text-left text-brand hover:underline"
                      onClick={() => {
                        form.set("owner_user_id", u.id);
                        form.set("owner_label", `${u.name} (${u.email})`);
                        setOwners([]);
                      }}
                    >
                      {u.name} — {u.email}
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {err("owner_user_id") && <p className="text-sm text-danger">{err("owner_user_id")}</p>}
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Действует с" error={err("valid_from")} hint="Пусто — сразу после публикации">
            <Input type="datetime-local" value={v.valid_from} disabled={locked("valid_from")} onChange={(e) => form.set("valid_from", e.target.value)} />
          </Field>
          <Field label="Действует до" error={err("valid_until")} hint="Пусто — без срока">
            <Input type="datetime-local" value={v.valid_until} disabled={locked("valid_until")} onChange={(e) => form.set("valid_until", e.target.value)} />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label={mode === "batch" ? "Лимит применений одного кода" : "Общий лимит применений"} error={err("total_limit")} hint={mode === "batch" ? "Обычно 1" : "Пусто — без лимита"}>
            <Input type="number" min={1} value={v.total_limit} disabled={locked("total_limit")} onChange={(e) => form.set("total_limit", e.target.value)} placeholder={mode === "batch" ? "1" : ""} />
          </Field>
          <Field label="Лимит на одного клиента" error={err("per_user_limit")} hint="Пусто — без лимита">
            <Input type="number" min={1} value={v.per_user_limit} disabled={locked("per_user_limit")} onChange={(e) => form.set("per_user_limit", e.target.value)} />
          </Field>
          <Field label="Минимальная цена сессии, ₽" error={err("min_amount")}>
            <Input inputMode="decimal" value={v.min_amount} disabled={locked("min_amount")} onChange={(e) => form.set("min_amount", e.target.value)} />
          </Field>
        </div>

        <fieldset className="grid gap-4 rounded-2xl border border-line p-4" disabled={restrictionsLocked}>
          <legend className="px-1 text-sm font-semibold">Ограничения</legend>
          <div className="grid gap-2">
            <p className="text-sm font-medium text-ink-2">Услуги (пусто — все)</p>
            <div className="flex flex-wrap gap-4">
              {(["individual", "pair"] as ServiceType[]).map((t) => (
                <Checkbox key={t} label={t === "pair" ? "Парная сессия" : "Индивидуальная сессия"} checked={v.service_types.includes(t)} onChange={() => toggle("service_types", t)} />
              ))}
            </div>
          </div>
          <div className="grid gap-2">
            <p className="text-sm font-medium text-ink-2">Психологи (пусто — все){v.psychologist_ids.length ? `: выбрано ${v.psychologist_ids.length}` : ""}</p>
            <Input className="max-w-xs" value={psyFilter} onChange={(e) => setPsyFilter(e.target.value)} placeholder="Найти психолога" />
            <div className="grid max-h-48 gap-1 overflow-y-auto rounded-xl border border-line p-3 sm:grid-cols-2">
              {psychologists.length === 0 && <p className="text-sm text-muted">{lookups ? "Никого не нашли" : "Загрузка…"}</p>}
              {psychologists.map((p) => (
                <Checkbox key={p.id} label={p.name} checked={v.psychologist_ids.includes(p.id)} onChange={() => toggle("psychologist_ids", p.id)} />
              ))}
            </div>
          </div>
          {lookups && lookups.price_categories.length > 0 && (
            <div className="grid gap-2">
              <p className="text-sm font-medium text-ink-2">Ценовые категории (пусто — все)</p>
              <div className="flex flex-wrap gap-4">
                {lookups.price_categories.map((c) => (
                  <Checkbox key={c.id} label={c.title} checked={v.price_category_ids.includes(c.id)} onChange={() => toggle("price_category_ids", c.id)} />
                ))}
              </div>
            </div>
          )}
          <div className="grid gap-3">
            <p className="text-sm font-medium text-ink-2">Сегмент клиентов</p>
            <Checkbox label="Только новые клиенты — без оплаченных сессий" checked={v.new_clients} onChange={(e) => form.set("new_clients", e.target.checked)} />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Зарегистрированы не раньше" error={err("restrictions.segment.registered_after")}>
                <Input type="date" value={v.registered_after} onChange={(e) => form.set("registered_after", e.target.value)} />
              </Field>
              <Field label="Проведено сессий не меньше" error={err("restrictions.segment.min_held_sessions")}>
                <Input type="number" min={1} value={v.min_held_sessions} onChange={(e) => form.set("min_held_sessions", e.target.value)} />
              </Field>
            </div>
          </div>
        </fieldset>

        {mode !== "edit" && (
          <Checkbox
            label={mode === "batch" ? "Сразу опубликовать коды" : "Сразу опубликовать — до начала периода код будет «Запланирован»"}
            checked={v.publish}
            onChange={(e) => form.set("publish", e.target.checked)}
          />
        )}

        <div className="flex flex-wrap gap-3">
          <Button type="submit" loading={form.submitting}>
            {mode === "create" ? "Создать" : mode === "batch" ? "Сгенерировать" : "Сохранить"}
          </Button>
          <Button type="button" variant="secondary" onClick={onCancel}>
            Отмена
          </Button>
        </div>
      </form>
    </Card>
  );
}
