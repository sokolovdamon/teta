"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { AuthCard } from "./AuthCard";
import { Alert, Button, Field, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { cabinetHome } from "@/lib/roles";
import type { User } from "@/lib/types";
import { useForm } from "@/lib/useForm";

export function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const next = params.get("next");
  const form = useForm({ email: "", password: "" });

  return (
    <AuthCard
      title="Вход в кабинет"
      footer={
        <>
          Нет аккаунта?{" "}
          <Link href={`/auth/register${next ? `?next=${encodeURIComponent(next)}` : ""}`} className="font-medium text-brand">
            Зарегистрироваться
          </Link>
        </>
      }
    >
      <form
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          form.submit(async (v) => {
            const res = await api<{ user: User }>("/auth/login", { method: "POST", body: v });
            router.push(next && next.startsWith("/") ? next : cabinetHome(res.user));
            router.refresh();
          });
        }}
      >
        {form.message && <Alert tone="danger">{form.message}</Alert>}
        <Field label="Email" error={form.errors.email}>
          <Input type="email" autoComplete="email" required value={form.values.email} onChange={(e) => form.set("email", e.target.value)} />
        </Field>
        <Field label="Пароль" error={form.errors.password}>
          <Input type="password" autoComplete="current-password" required value={form.values.password} onChange={(e) => form.set("password", e.target.value)} />
        </Field>
        <Button type="submit" loading={form.submitting} className="w-full">
          Войти
        </Button>
        <Link href="/auth/forgot" className="text-center text-sm text-brand">
          Забыли пароль?
        </Link>
      </form>
    </AuthCard>
  );
}
