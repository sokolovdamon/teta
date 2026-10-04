"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { AuthCard } from "./AuthCard";
import { Alert, Button, Field, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { cabinetHome } from "@/lib/roles";
import type { User } from "@/lib/types";
import { useForm } from "@/lib/useForm";

export function ResetForm() {
  const router = useRouter();
  const params = useSearchParams();
  const form = useForm({ password: "", password_confirmation: "" });

  return (
    <AuthCard title="Новый пароль">
      <form
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          form.submit(async (v) => {
            const res = await api<{ user: User }>("/auth/reset-password", {
              method: "POST",
              body: { ...v, token: params.get("token"), email: params.get("email") },
            });
            router.push(cabinetHome(res.user));
            router.refresh();
          });
        }}
      >
        {(form.message || form.errors.token) && <Alert tone="danger">{form.message ?? form.errors.token}</Alert>}
        <Field label="Новый пароль" hint="Не короче 8 символов, буквы и цифры" error={form.errors.password}>
          <Input type="password" required autoComplete="new-password" value={form.values.password} onChange={(e) => form.set("password", e.target.value)} />
        </Field>
        <Field label="Повтор пароля">
          <Input type="password" required autoComplete="new-password" value={form.values.password_confirmation} onChange={(e) => form.set("password_confirmation", e.target.value)} />
        </Field>
        <Button type="submit" loading={form.submitting}>
          Сохранить и войти
        </Button>
      </form>
    </AuthCard>
  );
}
