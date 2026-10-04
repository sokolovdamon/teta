"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";

export function EmailVerifyBanner({ email }: { email: string }) {
  const [state, setState] = useState<"idle" | "sent" | "error">("idle");
  const [error, setError] = useState("");
  return (
    <div className="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-warning bg-warning-soft px-4 py-3 text-[15px]">
      <p className="flex-1">
        Подтвердите email <b>{email}</b> — без этого нельзя записаться на сессию.
        {state === "sent" && " Письмо отправлено."}
        {state === "error" && ` ${error}`}
      </p>
      <button
        type="button"
        className="font-medium text-brand"
        onClick={() =>
          api("/auth/email/resend", { method: "POST" })
            .then(() => setState("sent"))
            .catch((e) => {
              setState("error");
              setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Ошибка отправки.");
            })
        }
      >
        Отправить письмо ещё раз
      </button>
    </div>
  );
}
