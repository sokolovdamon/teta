"use client";

import Link from "next/link";
import { useState } from "react";
import { AuthCard } from "./AuthCard";
import { Alert, Button, Field, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { useForm } from "@/lib/useForm";

export function ForgotForm() {
  const [sent, setSent] = useState(false);
  const form = useForm({ email: "" });

  return (
    <AuthCard title="Восстановление пароля" subtitle="Пришлём ссылку для смены пароля на ваш email." footer={<Link href="/auth/login" className="text-brand">Вернуться ко входу</Link>}>
      {sent ? (
        <Alert tone="success" title="Проверьте почту">
          Если аккаунт с таким email существует, мы отправили на него ссылку для смены пароля.
        </Alert>
      ) : (
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.submit(async (v) => {
              await api("/auth/forgot-password", { method: "POST", body: v });
              setSent(true);
            });
          }}
        >
          {form.message && <Alert tone="danger">{form.message}</Alert>}
          <Field label="Email" error={form.errors.email}>
            <Input type="email" required value={form.values.email} onChange={(e) => form.set("email", e.target.value)} />
          </Field>
          <Button type="submit" loading={form.submitting}>
            Отправить ссылку
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
