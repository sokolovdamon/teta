"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { AuthCard } from "./AuthCard";
import { Alert, Button, Checkbox, Field, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { cabinetHome } from "@/lib/roles";
import type { User } from "@/lib/types";
import { useForm } from "@/lib/useForm";

/** X-01 / WIZ-05: email and password; separate consent to personal data (DEC-29, DEC-49: 18+). */
export function RegisterForm() {
  const router = useRouter();
  const params = useSearchParams();
  const next = params.get("next");
  const asPsychologist = params.get("role") === "psychologist";
  const form = useForm({
    name: "",
    last_name: "",
    email: "",
    birth_date: "",
    password: "",
    password_confirmation: "",
    accept_personal_data: false,
    accept_terms: false,
    marketing_opt_in: false,
    referral_code: params.get("ref") ?? "",
  });

  return (
    <AuthCard
      title={asPsychologist ? "Регистрация психолога" : "Регистрация"}
      subtitle={
        asPsychologist
          ? "После регистрации заполните профиль и загрузите дипломы — администратор проверит квалификацию."
          : "Аккаунт нужен, чтобы записаться на сессию и вести дневник эмоций."
      }
      footer={
        <>
          Уже есть аккаунт?{" "}
          <Link href={`/auth/login${next ? `?next=${encodeURIComponent(next)}` : ""}`} className="font-medium text-brand">
            Войти
          </Link>
        </>
      }
    >
      <form
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          form.submit(async (v) => {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const res = await api<{ user: User }>("/auth/register", {
              method: "POST",
              body: { ...v, timezone, role: asPsychologist ? "psychologist" : "client", referral_code: v.referral_code || undefined },
            });
            router.push(next && next.startsWith("/") ? next : cabinetHome(res.user));
            router.refresh();
          });
        }}
      >
        {form.message && <Alert tone="danger">{form.message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={asPsychologist ? "Имя" : "Имя или псевдоним"} error={form.errors.name}>
            <Input required autoComplete="given-name" value={form.values.name} onChange={(e) => form.set("name", e.target.value)} />
          </Field>
          <Field label={asPsychologist ? "Фамилия" : "Фамилия (необязательно)"} error={form.errors.last_name}>
            <Input required={asPsychologist} autoComplete="family-name" value={form.values.last_name} onChange={(e) => form.set("last_name", e.target.value)} />
          </Field>
        </div>
        <Field label="Email" error={form.errors.email}>
          <Input type="email" required autoComplete="email" value={form.values.email} onChange={(e) => form.set("email", e.target.value)} />
        </Field>
        <Field label="Дата рождения" hint="Платформа работает с клиентами от 18 лет" error={form.errors.birth_date}>
          <Input type="date" required value={form.values.birth_date} onChange={(e) => form.set("birth_date", e.target.value)} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Пароль" hint="Не короче 8 символов, буквы и цифры" error={form.errors.password}>
            <Input type="password" required autoComplete="new-password" value={form.values.password} onChange={(e) => form.set("password", e.target.value)} />
          </Field>
          <Field label="Повтор пароля">
            <Input
              type="password"
              required
              autoComplete="new-password"
              value={form.values.password_confirmation}
              onChange={(e) => form.set("password_confirmation", e.target.value)}
            />
          </Field>
        </div>
        <div className="grid gap-3 pt-1">
          <Checkbox
            checked={form.values.accept_personal_data}
            onChange={(e) => form.set("accept_personal_data", e.target.checked)}
            label={
              <>
                Даю{" "}
                <Link href="/legal/personal-data" target="_blank" className="text-brand underline">
                  согласие на обработку персональных данных
                </Link>
              </>
            }
          />
          {form.errors.accept_personal_data && <p className="text-sm text-danger">{form.errors.accept_personal_data}</p>}
          <Checkbox
            checked={form.values.accept_terms}
            onChange={(e) => form.set("accept_terms", e.target.checked)}
            label={
              <>
                Принимаю{" "}
                <Link href="/legal/terms" target="_blank" className="text-brand underline">
                  пользовательское соглашение
                </Link>
              </>
            }
          />
          {form.errors.accept_terms && <p className="text-sm text-danger">{form.errors.accept_terms}</p>}
          <Checkbox
            checked={form.values.marketing_opt_in}
            onChange={(e) => form.set("marketing_opt_in", e.target.checked)}
            label="Хочу получать полезные материалы и новости ТЕТА (можно отписаться в любой момент)"
          />
        </div>
        <Button type="submit" loading={form.submitting} className="w-full">
          Зарегистрироваться
        </Button>
        {!asPsychologist && (
          <Link href="/auth/register?role=psychologist" className="text-center text-sm text-brand">
            Я психолог и хочу работать на платформе
          </Link>
        )}
      </form>
    </AuthCard>
  );
}
