"use client";

import { useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { AuthCard } from "./AuthCard";
import { Alert, LinkButton } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export function VerifyEmail() {
  const params = useSearchParams();
  const [state, setState] = useState<"pending" | "ok" | "error">("pending");
  const [error, setError] = useState<string>("");

  useEffect(() => {
    const body = { uid: params.get("uid"), email: params.get("email"), exp: Number(params.get("exp")), sig: params.get("sig") };
    api("/auth/email/verify", { method: "POST", body })
      .then(() => setState("ok"))
      .catch((e) => {
        setState("error");
        setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось подтвердить email.");
      });
  }, [params]);

  return (
    <AuthCard title="Подтверждение email">
      {state === "pending" && <p className="text-ink-2">Проверяем ссылку…</p>}
      {state === "ok" && (
        <div className="grid gap-4">
          <Alert tone="success" title="Email подтверждён">
            Теперь можно записываться на сессии и получать уведомления.
          </Alert>
          <LinkButton href="/auth/login">Перейти в кабинет</LinkButton>
        </div>
      )}
      {state === "error" && (
        <div className="grid gap-4">
          <Alert tone="danger">{error}</Alert>
          <LinkButton href="/client/settings" variant="secondary">
            Отправить письмо ещё раз
          </LinkButton>
        </div>
      )}
    </AuthCard>
  );
}
