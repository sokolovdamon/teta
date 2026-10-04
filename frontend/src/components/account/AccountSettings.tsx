"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert, Button, Card, Checkbox, Field, Input, Select } from "@/components/ui";
import { api } from "@/lib/api";
import { date } from "@/lib/format";
import type { User } from "@/lib/types";
import { useForm } from "@/lib/useForm";

const TIMEZONES = [
  "Europe/Kaliningrad", "Europe/Moscow", "Europe/Samara", "Asia/Yekaterinburg", "Asia/Omsk", "Asia/Novosibirsk",
  "Asia/Krasnoyarsk", "Asia/Irkutsk", "Asia/Yakutsk", "Asia/Vladivostok", "Asia/Magadan", "Asia/Kamchatka",
  "Europe/Minsk", "Asia/Almaty", "Asia/Tbilisi", "Asia/Yerevan", "Europe/Istanbul", "Europe/Berlin", "Asia/Dubai",
];

type Consent = { id: string; purpose: string; document: string; slug: string; version: string; accepted_at: string; revoked_at: string | null };
type Prefs = { session_reminders: boolean; marketing_emails: boolean; product_news: boolean };

/** CL-08 / PRO-10: profile, email, password, timezone, subscriptions and consents, account deletion. */
export function AccountSettings({ user }: { user: User }) {
  const router = useRouter();
  const profile = useForm({ name: user.name, last_name: user.last_name ?? "", phone: user.phone ?? "", timezone: user.timezone });
  const email = useForm({ email: "", password: "" });
  const password = useForm({ current_password: "", password: "", password_confirmation: "" });
  const removal = useForm({ password: "" });
  const [saved, setSaved] = useState<string | null>(null);
  const [prefs, setPrefs] = useState<Prefs | null>(null);
  const [consents, setConsents] = useState<Consent[]>([]);

  useEffect(() => {
    api<{ data: Prefs }>("/account/notification-preferences").then((r) => setPrefs(r.data)).catch(() => null);
    api<{ data: Consent[] }>("/account/consents").then((r) => setConsents(r.data)).catch(() => null);
  }, []);

  async function togglePref(key: keyof Prefs, value: boolean) {
    setPrefs((p) => (p ? { ...p, [key]: value } : p));
    await api("/account/notification-preferences", { method: "PATCH", body: { [key]: value } });
    if (key === "marketing_emails") await api("/account/consents", { method: "POST", body: { purpose: "mailing", accepted: value } });
  }

  return (
    <div className="grid max-w-3xl gap-6">
      {saved && <Alert tone="success">{saved}</Alert>}

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Профиль</h2>
        <form
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            profile.submit(async (v) => {
              await api("/account/profile", { method: "PATCH", body: v });
              setSaved("Профиль сохранён");
              router.refresh();
            });
          }}
        >
          <Field label="Имя или псевдоним" error={profile.errors.name}>
            <Input value={profile.values.name} onChange={(e) => profile.set("name", e.target.value)} required />
          </Field>
          <Field label="Фамилия" error={profile.errors.last_name}>
            <Input value={profile.values.last_name} onChange={(e) => profile.set("last_name", e.target.value)} />
          </Field>
          <Field label="Телефон" error={profile.errors.phone}>
            <Input type="tel" value={profile.values.phone} onChange={(e) => profile.set("phone", e.target.value)} />
          </Field>
          <Field label="Часовой пояс" hint="Время сессий показываем в этом поясе" error={profile.errors.timezone}>
            <Select value={profile.values.timezone} onChange={(e) => profile.set("timezone", e.target.value)}>
              {[...new Set([profile.values.timezone, ...TIMEZONES])].map((tz) => (
                <option key={tz} value={tz}>
                  {tz.replace("_", " ")}
                </option>
              ))}
            </Select>
          </Field>
          <div className="sm:col-span-2">
            <Button type="submit" loading={profile.submitting}>
              Сохранить
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <h2 className="mb-1 text-lg font-semibold">Email</h2>
        <p className="mb-4 text-ink-2">
          Сейчас: <b>{user.email}</b>
          {user.pending_email && <> · ожидает подтверждения: {user.pending_email}</>}
        </p>
        <form
          className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            email.submit(async (v) => {
              await api("/account/email", { method: "POST", body: v });
              setSaved("Письмо для подтверждения отправлено на новый адрес");
              router.refresh();
            });
          }}
        >
          <Field label="Новый email" error={email.errors.email}>
            <Input type="email" required value={email.values.email} onChange={(e) => email.set("email", e.target.value)} />
          </Field>
          <Field label="Текущий пароль" error={email.errors.password}>
            <Input type="password" required value={email.values.password} onChange={(e) => email.set("password", e.target.value)} />
          </Field>
          <Button type="submit" variant="secondary" loading={email.submitting}>
            Сменить
          </Button>
        </form>
      </Card>

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Пароль</h2>
        <form
          className="grid gap-4 sm:grid-cols-3 sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            password.submit(async (v) => {
              await api("/account/password", { method: "PATCH", body: v });
              setSaved("Пароль изменён. Другие устройства вышли из аккаунта.");
            });
          }}
        >
          <Field label="Текущий пароль" error={password.errors.current_password}>
            <Input type="password" required value={password.values.current_password} onChange={(e) => password.set("current_password", e.target.value)} />
          </Field>
          <Field label="Новый пароль" error={password.errors.password}>
            <Input type="password" required value={password.values.password} onChange={(e) => password.set("password", e.target.value)} />
          </Field>
          <Field label="Повтор">
            <Input type="password" required value={password.values.password_confirmation} onChange={(e) => password.set("password_confirmation", e.target.value)} />
          </Field>
          <div className="sm:col-span-3">
            <Button type="submit" variant="secondary" loading={password.submitting}>
              Изменить пароль
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Письма и подписки</h2>
        {prefs ? (
          <div className="grid gap-3">
            <Checkbox checked={prefs.session_reminders} onChange={(e) => togglePref("session_reminders", e.target.checked)} label="Напоминания о сессиях" />
            <Checkbox checked={prefs.marketing_emails} onChange={(e) => togglePref("marketing_emails", e.target.checked)} label="Полезные материалы и предложения ТЕТА" />
            <Checkbox checked={prefs.product_news} onChange={(e) => togglePref("product_news", e.target.checked)} label="Новости платформы" />
            <p className="text-sm text-muted">Письма о записях, оплатах и безопасности аккаунта приходят всегда.</p>
          </div>
        ) : (
          <p className="text-muted">Загрузка…</p>
        )}
      </Card>

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Согласия</h2>
        {consents.length === 0 ? (
          <p className="text-muted">Согласий пока нет.</p>
        ) : (
          <ul className="grid gap-2">
            {consents.map((c) => (
              <li key={c.id} className="flex flex-wrap justify-between gap-2 border-b border-line pb-2 text-[15px] last:border-0">
                <a href={`/legal/${c.slug}`} className="text-brand">
                  {c.document}, версия {c.version}
                </a>
                <span className="text-muted">
                  {c.revoked_at ? `отозвано ${date(c.revoked_at)}` : `принято ${date(c.accepted_at)}`}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card className="border-danger/40">
        <h2 className="mb-1 text-lg font-semibold">Удаление аккаунта</h2>
        {user.deletion_requested_at ? (
          <div className="grid gap-3">
            <p className="text-ink-2">Удаление запрошено {date(user.deletion_requested_at)}. Пока срок не истёк, его можно отменить.</p>
            <Button
              variant="secondary"
              onClick={async () => {
                await api("/account/delete/cancel", { method: "POST" });
                router.refresh();
              }}
            >
              Отменить удаление
            </Button>
          </div>
        ) : (
          <form
            className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
            onSubmit={(e) => {
              e.preventDefault();
              if (!window.confirm("Удалить аккаунт? Данные будут удалены через 30 дней.")) return;
              removal.submit(async (v) => {
                await api("/account/delete", { method: "POST", body: v });
                router.refresh();
              });
            }}
          >
            <p className="text-ink-2 sm:col-span-2">Данные удаляются через 30 дней после запроса. До этого удаление можно отменить.</p>
            <Field label="Пароль для подтверждения" error={removal.errors.password}>
              <Input type="password" required value={removal.values.password} onChange={(e) => removal.set("password", e.target.value)} />
            </Field>
            <Button type="submit" variant="danger" loading={removal.submitting}>
              Удалить аккаунт
            </Button>
          </form>
        )}
      </Card>
    </div>
  );
}
