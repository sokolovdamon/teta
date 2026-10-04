"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert, Button, Card, EmptyState, Field, LinkButton, Modal, Select } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { date, plural, rub } from "@/lib/format";
import type { ClientHome, MyPsychologist } from "./types";

const REASONS = [
  { value: "", label: "Не указывать" },
  { value: "approach", label: "Не совпали по подходу" },
  { value: "time", label: "Неудобное время" },
  { value: "contact", label: "Не почувствовал(а) контакта" },
  { value: "other", label: "Другое" },
];

type ChangeResult = { cancelled: number; credited_amount: number };

/**
 * CL-04: current psychologists and the change of psychologist (DEC-48, SEQ-04). The list comes from BOOK
 * (`/booking/my-psychologists`); until it is available the home endpoint provides the same data.
 */
export function MyPsychologists({ timezone }: { timezone: string }) {
  const [items, setItems] = useState<MyPsychologist[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [changing, setChanging] = useState<MyPsychologist | null>(null);
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<(ChangeResult & { name: string }) | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let alive = true;
    loadPsychologists()
      .then((list) => alive && setItems(list))
      .catch(() => alive && setFailed(true));
    return () => {
      alive = false;
    };
  }, [reloadKey]);

  async function confirmChange() {
    if (!changing) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await api<ChangeResult | { data: ChangeResult }>("/booking/change-psychologist", {
        method: "POST",
        body: { psychologist_id: changing.psychologist_id, reason: reason || undefined },
      });
      const data = "data" in res ? res.data : res;
      setResult({ ...data, name: changing.name });
      setChanging(null);
      setReason("");
      setReloadKey((k) => k + 1);
    } catch (e) {
      if (e instanceof ApiError && (e.status === 404 || e.status === 405)) {
        setError("Смена психолога в кабинете скоро заработает. Пока напишите в поддержку — администратор поможет отменить сессии и вернуть оплату на баланс.");
      } else {
        setError(e instanceof ApiError ? e.message : "Не удалось связаться с сервером. Попробуйте ещё раз.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (failed) return <Alert tone="danger">Не удалось загрузить данные. Обновите страницу.</Alert>;
  if (items === null) return <p className="text-muted">Загрузка…</p>;

  return (
    <div className="grid gap-6">
      {result && (
        <Card className="grid gap-3 border-success">
          <h2 className="text-lg font-semibold">Готово: вы больше не работаете с {result.name}</h2>
          <p className="text-ink-2">
            {result.cancelled > 0
              ? `Отменено ${result.cancelled} ${plural(result.cancelled, ["сессия", "сессии", "сессий"])}.`
              : "Назначенных сессий не было."}{" "}
            {result.credited_amount > 0 && `На баланс личного кабинета зачислено ${rub(result.credited_amount)}.`}
          </p>
          <p className="text-ink-2">Теперь можно выбрать нового специалиста: пройдите анкету заново или посмотрите каталог.</p>
          <div className="flex flex-wrap gap-3">
            <LinkButton href="/podbor?again=1">Пройти анкету заново</LinkButton>
            <LinkButton href="/psychologists" variant="secondary">
              Открыть каталог
            </LinkButton>
          </div>
        </Card>
      )}

      {items.length === 0 && !result && (
        <EmptyState
          title="Психолог пока не выбран"
          description="Анкета подбора займёт несколько минут: мы предложим подходящего специалиста и все альтернативы."
          action={
            <div className="flex flex-wrap justify-center gap-3">
              <LinkButton href="/podbor">Подобрать психолога</LinkButton>
              <LinkButton href="/psychologists" variant="secondary">
                Каталог
              </LinkButton>
            </div>
          }
        />
      )}

      {items.map((p) => (
        <Card key={p.psychologist_id} className="grid gap-4">
          <div className="flex flex-wrap items-center gap-4">
            {p.photo_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- files are served from project storage
              <img src={p.photo_url} alt="" className="size-20 rounded-full object-cover" />
            ) : (
              <span className="grid size-20 place-items-center rounded-full bg-brand-soft text-2xl font-semibold text-brand" aria-hidden>
                {p.name.slice(0, 1)}
              </span>
            )}
            <div className="grid min-w-0 gap-1">
              <h2 className="text-xl font-semibold">{p.name}</h2>
              {p.headline && <p className="text-ink-2">{p.headline}</p>}
              <p className="text-sm text-muted">
                {p.upcoming_sessions > 0
                  ? `Назначено ${p.upcoming_sessions} ${plural(p.upcoming_sessions, ["сессия", "сессии", "сессий"])}`
                  : "Назначенных сессий нет"}
                {p.last_session_at && ` · последняя проведённая ${date(p.last_session_at, timezone)}`}
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-3">
            <LinkButton href={p.profile_url ?? `/psychologists/${p.slug}`} variant="secondary">
              Профиль психолога
            </LinkButton>
            <LinkButton href="/client/sessions" variant="ghost">
              Сессии
            </LinkButton>
            <Button variant="ghost" onClick={() => setChanging(p)}>
              Сменить психолога
            </Button>
          </div>
        </Card>
      ))}

      <Card className="grid gap-2">
        <h2 className="text-lg font-semibold">Искать своего специалиста — нормально</h2>
        <p className="text-ink-2">
          Если не получается контакт или подход не подходит, можно пройти анкету заново и выбрать другого психолога. Смена бесплатна и не ограничена по числу.
        </p>
        <div className="flex flex-wrap gap-3">
          <Link href="/podbor?again=1" className="font-medium text-brand">
            Пройти анкету заново →
          </Link>
          <Link href="/psychologists" className="font-medium text-brand">
            Каталог психологов →
          </Link>
        </div>
      </Card>

      <Modal
        open={changing !== null}
        onClose={() => {
          setChanging(null);
          setError(null);
        }}
        title="Сменить психолога?"
        footer={
          <>
            <Button variant="secondary" onClick={() => setChanging(null)}>
              Остаться
            </Button>
            <Button onClick={() => void confirmChange()} loading={submitting}>
              Сменить психолога
            </Button>
          </>
        }
      >
        {changing && (
          <div className="grid gap-4 text-ink-2">
            <p>Что произойдёт, если вы перестанете работать с {changing.name}:</p>
            <ul className="grid list-disc gap-2 pl-5">
              <li>
                Назначенные, но ещё не проведённые сессии будут отменены
                {changing.upcoming_sessions > 0 && ` (сейчас их ${changing.upcoming_sessions})`}.
              </li>
              <li>Деньги за оплаченные сессии вернутся на баланс личного кабинета: им можно оплатить сессии с новым психологом или вывести на карту.</li>
              <li>Проведённые сессии, рекомендации и дневник эмоций останутся у вас.</li>
              <li>Прежний психолог будет видеть динамику вашего дневника только до сегодняшнего дня. Причину смены ему не сообщаем.</li>
            </ul>
            <Field label="Почему решили сменить? (необязательно)" hint="Ответ видит только команда ТЕТА — он помогает улучшать подбор.">
              <Select value={reason} onChange={(e) => setReason(e.target.value)}>
                {REASONS.map((r) => (
                  <option key={r.value} value={r.value}>
                    {r.label}
                  </option>
                ))}
              </Select>
            </Field>
            {error && (
              <Alert tone="warning">
                {error}{" "}
                <Link href="/client/chat" className="font-medium text-brand">
                  Написать в поддержку
                </Link>
              </Alert>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}

/** BOOK's list when it exists, otherwise the same data from the cabinet home endpoint. */
async function loadPsychologists(): Promise<MyPsychologist[]> {
  try {
    const res = await api<{ data: MyPsychologist[] }>("/booking/my-psychologists");
    const home = await api<{ data: ClientHome }>("/client/home").catch(() => null);
    const cards = new Map((home?.data.psychologists ?? []).map((p) => [p.psychologist_id, p]));
    return res.data.map((p) => ({ ...cards.get(p.psychologist_id), ...p }));
  } catch (e) {
    if (!(e instanceof ApiError) || (e.status !== 404 && e.status !== 405)) throw e;
    const home = await api<{ data: ClientHome }>("/client/home");
    return home.data.psychologists;
  }
}
