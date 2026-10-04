"use client";

import { clsx } from "clsx";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Alert, Badge, Button, Checkbox, EmptyState, Field, Input, Markdown, Modal, Select, Table, Textarea, type Column } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { rub } from "@/lib/format";
import { kopecksToRubles, rublesToKopecks } from "./schedule";

type Item = Record<string, unknown> & { id: string };
type FieldType = "text" | "slug" | "textarea" | "markdown" | "number" | "bool" | "format" | "group" | "money";
type FieldDef = { name: string; label: string; type: FieldType; required?: boolean; hint?: string; wide?: boolean };
type TypeDef = { type: string; title: string; description: string; fields: FieldDef[]; columns: string[] };

const TYPES: TypeDef[] = [
  {
    type: "requests",
    title: "Запросы",
    description: "43 запроса в группах (DEC-11). У каждого — посадочная страница /help/{slug} или /help/para/{slug}, тексты и SEO-поля; снятый с публикации запрос не виден на сайте.",
    columns: ["title", "request_group_id", "format", "carousel_sort", "is_published"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "slug", label: "Адрес страницы (slug)", type: "slug", required: true, hint: "Латиница, цифры и дефис" },
      { name: "request_group_id", label: "Группа", type: "group", required: true },
      { name: "format", label: "Формат", type: "format" },
      { name: "age_label", label: "Пометка возраста", type: "text", hint: "Например, 18+" },
      { name: "carousel_sort", label: "Порядок в карусели", type: "number" },
      { name: "is_published", label: "Опубликован", type: "bool" },
      { name: "seo_title", label: "SEO-заголовок", type: "text", wide: true },
      { name: "seo_description", label: "SEO-описание", type: "textarea", wide: true },
      { name: "landing_lead", label: "Лид посадочной страницы", type: "textarea", wide: true },
      { name: "landing_body", label: "Текст посадочной страницы (Markdown)", type: "markdown", wide: true },
    ],
  },
  {
    type: "request-groups",
    title: "Группы запросов",
    description: "Группы карусели запросов на главной и хаба /help.",
    columns: ["title", "slug", "format", "sort"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "slug", label: "Код (slug)", type: "slug", required: true },
      { name: "format", label: "Формат", type: "format" },
      { name: "sort", label: "Порядок", type: "number" },
    ],
  },
  {
    type: "approaches",
    title: "Подходы",
    description: "Подходы с пояснениями: общее пояснение показывается клиентам, а психолог добавляет, как применяет подход сам.",
    columns: ["title", "slug", "sort", "is_active"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "slug", label: "Код (slug)", type: "slug", required: true },
      { name: "explanation", label: "Пояснение для клиентов", type: "textarea", wide: true },
      { name: "sort", label: "Порядок", type: "number" },
      { name: "is_active", label: "Активен", type: "bool" },
    ],
  },
  {
    type: "specializations",
    title: "Специализации",
    description: "Специализации психологов для профиля и фильтров каталога.",
    columns: ["title", "slug", "sort", "is_active"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "slug", label: "Код (slug)", type: "slug", required: true },
      { name: "sort", label: "Порядок", type: "number" },
      { name: "is_active", label: "Активна", type: "bool" },
    ],
  },
  {
    type: "service-types",
    title: "Типы услуг",
    description: "Индивидуальная сессия — 50 минут, парная — 90 минут (DEC-19); супервизия, интервизия, мероприятия.",
    columns: ["title", "code", "duration_min", "is_active"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "code", label: "Код", type: "slug", required: true },
      { name: "duration_min", label: "Длительность, мин", type: "number", required: true },
      { name: "is_active", label: "Активен", type: "bool" },
    ],
  },
  {
    type: "price-categories",
    title: "Ценовые категории",
    description: "Категория психолога определяется ценой индивидуальной сессии (DEC-55). Границы применяются к каталогу сразу.",
    columns: ["title", "code", "min_price", "max_price", "sort"],
    fields: [
      { name: "title", label: "Название", type: "text", required: true },
      { name: "code", label: "Код", type: "slug", required: true },
      { name: "min_price", label: "От, ₽ (включительно)", type: "money", required: true },
      { name: "max_price", label: "До, ₽ (включительно, пусто — без верхней границы)", type: "money" },
      { name: "sort", label: "Порядок", type: "number" },
    ],
  },
];

const FORMAT_LABEL: Record<string, string> = { individual: "Индивидуальный", pair: "Парный" };

/** ADM-13: dictionaries without code changes; changes are audited by the API. */
export function AdminDictionaries({ canManage }: { canManage: boolean }) {
  const [tab, setTab] = useState(TYPES[0].type);
  const def = TYPES.find((t) => t.type === tab)!;
  const [loaded, setLoaded] = useState<{ key: string; items: Item[] | "error" } | null>(null);
  const [version, setVersion] = useState(0);
  const [groups, setGroups] = useState<Item[]>([]);
  const [editing, setEditing] = useState<Item | "new" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const key = `${tab}:${version}`;
  const items = loaded?.key === key && loaded.items !== "error" ? loaded.items : null;
  const error = loaded?.key === key && loaded.items === "error" ? "Не удалось загрузить справочник." : null;
  const load = useCallback(() => setVersion((v) => v + 1), []);

  useEffect(() => {
    const controller = new AbortController();
    api<{ data: Item[] }>(`/admin/dictionaries/${tab}`, { signal: controller.signal })
      .then((r) => setLoaded({ key: `${tab}:${version}`, items: r.data }))
      .catch((e: unknown) => {
        if ((e as { name?: string })?.name !== "AbortError") setLoaded({ key: `${tab}:${version}`, items: "error" });
      });
    return () => controller.abort();
  }, [tab, version]);
  useEffect(() => {
    api<{ data: Item[] }>("/admin/dictionaries/request-groups")
      .then((r) => setGroups(r.data))
      .catch(() => undefined);
  }, []);

  const groupTitle = useMemo(() => Object.fromEntries(groups.map((g) => [g.id, String(g.title)])), [groups]);

  async function remove(item: Item) {
    if (!window.confirm(`Удалить «${String(item.title)}»?`)) return;
    try {
      await api(`/admin/dictionaries/${tab}/${item.id}`, { method: "DELETE" });
      setNotice("Удалено.");
      load();
    } catch (e) {
      setNotice(e instanceof ApiError ? e.message : "Не удалось удалить.");
    }
  }

  const columns: Column<Item>[] = [
    ...def.columns.map((name) => ({
      key: name,
      title: def.fields.find((f) => f.name === name)?.label ?? name,
      render: (row: Item) => renderValue(def.fields.find((f) => f.name === name)?.type ?? "text", row[name], groupTitle),
    })),
    ...(canManage
      ? [
          {
            key: "actions",
            title: "",
            className: "text-right",
            render: (row: Item) => (
              <span className="flex justify-end gap-1">
                <Button size="sm" variant="ghost" onClick={() => setEditing(row)}>
                  Изменить
                </Button>
                <Button size="sm" variant="ghost" onClick={() => remove(row)}>
                  Удалить
                </Button>
              </span>
            ),
          },
        ]
      : []),
  ];

  return (
    <div className="grid gap-5">
      <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-line">
        {TYPES.map((t) => (
          <button
            key={t.type}
            role="tab"
            type="button"
            aria-selected={t.type === tab}
            onClick={() => {
              setTab(t.type);
              setNotice(null);
            }}
            className={clsx(
              "-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-[15px]",
              t.type === tab ? "border-brand font-medium text-brand" : "border-transparent text-ink-2 hover:text-ink",
            )}
          >
            {t.title}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl text-ink-2">{def.description}</p>
        {canManage && <Button onClick={() => setEditing("new")}>Добавить</Button>}
      </div>
      {!canManage && <Alert tone="info">У вашей роли есть только просмотр справочников.</Alert>}
      {notice && <Alert tone="info">{notice}</Alert>}
      {error && <Alert tone="danger">{error}</Alert>}
      {items === null && !error && <p className="text-muted">Загрузка…</p>}
      {items && <Table columns={columns} rows={items} rowKey={(r) => r.id} empty={<EmptyState title="Справочник пуст" />} />}

      {editing && (
        <EditModal
          def={def}
          item={editing === "new" ? null : editing}
          groups={groups}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setNotice("Сохранено.");
            load();
            if (tab === "request-groups") api<{ data: Item[] }>("/admin/dictionaries/request-groups").then((r) => setGroups(r.data)).catch(() => undefined);
          }}
        />
      )}
    </div>
  );
}

function renderValue(type: FieldType, value: unknown, groupTitle: Record<string, string>) {
  if (type === "bool") return value ? <Badge tone="success">Да</Badge> : <Badge>Нет</Badge>;
  if (type === "format") return FORMAT_LABEL[String(value)] ?? "—";
  if (type === "group") return groupTitle[String(value)] ?? "—";
  if (type === "money") return value === null || value === undefined ? "без границы" : <span className="num">{rub(Number(value))}</span>;
  if (value === null || value === undefined || value === "") return <span className="text-muted">—</span>;
  return String(value);
}

function EditModal({ def, item, groups, onClose, onSaved }: { def: TypeDef; item: Item | null; groups: Item[]; onClose: () => void; onSaved: () => void }) {
  const [values, setValues] = useState<Record<string, unknown>>(() => {
    const init: Record<string, unknown> = {};
    for (const f of def.fields) {
      const v = item?.[f.name];
      init[f.name] = f.type === "money" ? kopecksToRubles((v as number | null) ?? null) : f.type === "bool" ? (v ?? true) : (v ?? (f.type === "format" ? "individual" : ""));
    }
    return init;
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [previewBody, setPreviewBody] = useState(false);

  async function save() {
    setBusy(true);
    setErrors({});
    setMessage(null);
    const body: Record<string, unknown> = {};
    for (const f of def.fields) {
      const v = values[f.name];
      if (f.type === "money") body[f.name] = rublesToKopecks(String(v ?? ""));
      else if (f.type === "number") body[f.name] = v === "" || v === null ? (f.required ? null : 0) : Number(v);
      else if (f.type === "bool") body[f.name] = Boolean(v);
      else body[f.name] = v === "" ? null : v;
    }
    try {
      await api(`/admin/dictionaries/${def.type}${item ? `/${item.id}` : ""}`, { method: item ? "PATCH" : "POST", body });
      onSaved();
    } catch (e) {
      if (e instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.errors)) flat[k] = v[0];
        setErrors(flat);
        if (!Object.keys(flat).length) setMessage(e.message);
      } else setMessage("Не удалось сохранить.");
    } finally {
      setBusy(false);
    }
  }

  const set = (name: string, value: unknown) => setValues((v) => ({ ...v, [name]: value }));

  return (
    <Modal
      open
      onClose={onClose}
      title={item ? `Изменить: ${String(item.title ?? "")}` : `Новая запись: ${def.title.toLowerCase()}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Отмена
          </Button>
          <Button onClick={save} loading={busy}>
            Сохранить
          </Button>
        </>
      }
    >
      <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1 sm:grid-cols-2">
        {message && <Alert tone="danger" className="sm:col-span-2">{message}</Alert>}
        {def.fields.map((f) => {
          const v = values[f.name];
          const wide = f.wide || f.type === "textarea" || f.type === "markdown" ? "sm:col-span-2" : undefined;
          if (f.type === "bool") {
            return (
              <Checkbox key={f.name} className={clsx("self-end pb-3", wide)} label={f.label} checked={Boolean(v)} onChange={(e) => set(f.name, e.target.checked)} />
            );
          }
          return (
            <Field key={f.name} label={`${f.label}${f.required ? " *" : ""}`} hint={f.hint} error={errors[f.name]} className={wide}>
              {f.type === "format" ? (
                <Select value={String(v)} onChange={(e) => set(f.name, e.target.value)}>
                  <option value="individual">Индивидуальный</option>
                  <option value="pair">Парный</option>
                </Select>
              ) : f.type === "group" ? (
                <Select value={String(v ?? "")} onChange={(e) => set(f.name, e.target.value)}>
                  <option value="">Выберите группу</option>
                  {groups.map((g) => (
                    <option key={g.id} value={g.id}>
                      {String(g.title)}
                    </option>
                  ))}
                </Select>
              ) : f.type === "textarea" ? (
                <Textarea value={String(v ?? "")} onChange={(e) => set(f.name, e.target.value)} rows={3} />
              ) : f.type === "markdown" ? (
                <div className="grid gap-2">
                  <div className="flex gap-2 text-sm">
                    <button type="button" className={clsx(!previewBody && "font-medium text-brand")} onClick={() => setPreviewBody(false)}>
                      Текст
                    </button>
                    <button type="button" className={clsx(previewBody && "font-medium text-brand")} onClick={() => setPreviewBody(true)}>
                      Предпросмотр
                    </button>
                  </div>
                  {previewBody ? (
                    <div className="min-h-40 rounded-xl border border-line p-4">
                      <Markdown>{String(v ?? "") || "_Пусто_"}</Markdown>
                    </div>
                  ) : (
                    <Textarea value={String(v ?? "")} onChange={(e) => set(f.name, e.target.value)} rows={10} className="font-mono text-sm" />
                  )}
                </div>
              ) : (
                <Input
                  value={String(v ?? "")}
                  inputMode={f.type === "number" || f.type === "money" ? "decimal" : undefined}
                  onChange={(e) => set(f.name, f.type === "slug" ? e.target.value.toLowerCase() : e.target.value)}
                />
              )}
            </Field>
          );
        })}
      </div>
    </Modal>
  );
}
