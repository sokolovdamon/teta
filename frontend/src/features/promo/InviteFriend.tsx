"use client";

import { useState, useSyncExternalStore } from "react";
import { Alert, Badge, Button, Card, EmptyState, Input, LinkButton } from "@/components/ui";
import { date, plural, rub } from "@/lib/format";
import { useResource } from "@/features/payouts/useResource";
import { promoTone } from "./form";
import type { InviteCode, InviteOverview } from "./types";

const noopSubscribe = () => () => {};

const inviteTone = { sent: "neutral", registered: "brand", first_paid: "brand", rewarded: "success", rejected: "neutral" } as const;

/** CL-13 (DEC-42): personal invite link, how the program works, invited friends and rewards. */
export function InviteFriend({ timezone }: { timezone: string }) {
  const res = useResource<{ data: InviteOverview }>("/client/invite");
  const [copied, setCopied] = useState<"link" | "code" | null>(null);
  // Web Share API exists only in some browsers; the server renders without the button.
  const canShare = useSyncExternalStore(
    noopSubscribe,
    () => typeof navigator.share === "function",
    () => false,
  );

  if (res.error && !res.data) {
    return (
      <Alert tone="danger" title="Раздел не загрузился">
        {res.error}{" "}
        <button type="button" className="text-brand underline" onClick={res.reload}>
          Повторить
        </button>
      </Alert>
    );
  }
  const d = res.data?.data;
  if (!d) return <p className="text-muted">Загрузка…</p>;

  const reward = d.terms.reward_type === "percent" ? `скидку ${d.terms.reward_value} %` : `скидку ${rub(d.terms.reward_value)}`;

  async function copy(text: string, what: "link" | "code") {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(what);
      setTimeout(() => setCopied(null), 2500);
    } catch {
      setCopied(null);
    }
  }

  async function share(url: string, discount: number) {
    try {
      await navigator.share({ title: "ТЕТА — психологи онлайн", text: `Скидка ${discount} % на первую сессию с психологом`, url });
    } catch {
      // The user closed the share sheet.
    }
  }

  return (
    <div className="grid gap-6">
      <Card className="grid gap-4 bg-brand-soft/40">
        <div className="grid gap-1">
          <h2 className="text-xl font-semibold">
            Другу — скидка {d.terms.friend_discount_percent} % на первую сессию, вам — {reward}
          </h2>
          <p className="text-ink-2">Отправьте другу личную ссылку. Когда он зарегистрируется и оплатит первую сессию, вы получите промокод на свою сессию.</p>
        </div>
        <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-center">
          <Input readOnly value={d.url} aria-label="Личная ссылка-приглашение" onFocus={(e) => e.currentTarget.select()} />
          <Button onClick={() => copy(d.url, "link")}>{copied === "link" ? "Ссылка скопирована" : "Скопировать ссылку"}</Button>
          {canShare && (
            <Button variant="secondary" onClick={() => share(d.url, d.terms.friend_discount_percent)}>
              Поделиться
            </Button>
          )}
        </div>
        <p className="text-sm text-ink-2">
          Код приглашения: <span className="font-mono font-semibold tracking-wider text-ink">{d.code}</span>{" "}
          <button type="button" className="text-brand underline" onClick={() => copy(d.code, "code")}>
            {copied === "code" ? "скопирован" : "скопировать"}
          </button>{" "}
          — друг может ввести его при регистрации.
        </p>
      </Card>

      {d.friend_code && (
        <Card className="grid gap-2">
          <h2 className="text-lg font-semibold">Ваш промокод от друга</h2>
          <CodeLine code={d.friend_code} timezone={timezone} />
          <p className="text-sm text-muted">Введите его при записи — скидка действует на первую оплаченную сессию.</p>
        </Card>
      )}

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Как это работает</h2>
        <ol className="grid gap-3 sm:grid-cols-3">
          {[
            ["Отправьте ссылку", "Поделитесь личной ссылкой или кодом приглашения с другом, которому может помочь психолог."],
            ["Друг регистрируется", `Он получает индивидуальный промокод: скидка ${d.terms.friend_discount_percent} % на первую оплаченную сессию.`],
            ["Вы получаете промокод", `После первой оплаченной сессии друга вам придёт промокод на ${reward}. Он действует ${d.terms.validity_days} ${plural(d.terms.validity_days, ["день", "дня", "дней"])}.`],
          ].map(([title, text], i) => (
            <li key={title} className="rounded-2xl border border-line bg-surface p-5">
              <p className="text-sm font-semibold text-brand">Шаг {i + 1}</p>
              <p className="mt-1 font-medium">{title}</p>
              <p className="mt-1 text-[15px] text-ink-2">{text}</p>
            </li>
          ))}
        </ol>
        <p className="text-sm text-muted">
          Программа для новых клиентов: приглашение самого себя, друга, который уже был клиентом, или оплата одной и той же картой не засчитываются. Сессии в рамках корпоративной
          программы не считаются оплаченными для программы. Скидка уменьшает только долю платформы — психолог получает полную оплату.
        </p>
      </section>

      <section className="grid gap-3">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-lg font-semibold">Приглашённые друзья</h2>
          {d.stats.invited > 0 && (
            <p className="text-sm text-muted">
              {d.stats.invited} {plural(d.stats.invited, ["приглашение", "приглашения", "приглашений"])}, промокодов получено: {d.stats.rewarded}
            </p>
          )}
        </div>
        {d.invites.length === 0 ? (
          <EmptyState title="Пока никого" description="Когда друг зарегистрируется по вашей ссылке, он появится здесь." />
        ) : (
          <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
            {d.invites.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                <div>
                  <p className="font-medium">{i.friend ?? "Друг"}</p>
                  <p className="text-sm text-muted">
                    {i.registered_at ? `Зарегистрировался ${date(i.registered_at, timezone)}` : ""}
                    {i.rewarded_at ? ` · промокод получен ${date(i.rewarded_at, timezone)}` : ""}
                    {i.rejected_reason ? ` · ${i.rejected_reason}` : ""}
                  </p>
                </div>
                <Badge tone={inviteTone[i.status]}>{i.status_label}</Badge>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Мои промокоды за друзей</h2>
        {d.rewards.length === 0 ? (
          <EmptyState title="Промокодов пока нет" description="Промокод появится после первой оплаченной сессии приглашённого друга." />
        ) : (
          <div className="grid gap-3">
            {d.rewards.map((c) => (
              <Card key={c.id}>
                <CodeLine code={c} timezone={timezone} />
              </Card>
            ))}
            <div>
              <LinkButton href="/psychologists" variant="secondary" size="sm">
                Записаться на сессию
              </LinkButton>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}

function CodeLine({ code, timezone }: { code: InviteCode; timezone: string }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p className="font-mono text-lg font-semibold tracking-wider">{code.code}</p>
        <p className="text-sm text-ink-2">
          {code.discount_label}
          {code.valid_until ? ` · действует до ${date(code.valid_until, timezone)}` : ""}
        </p>
      </div>
      <Badge tone={code.used ? "neutral" : promoTone[code.status]}>{code.used ? "Использован" : code.status_label}</Badge>
    </div>
  );
}
