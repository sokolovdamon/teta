"use client";

import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { Button } from "@/components/ui";

export function LogoutButton({ className }: { className?: string }) {
  const router = useRouter();
  return (
    <Button
      variant="secondary"
      size="sm"
      className={className}
      onClick={async () => {
        await api("/auth/logout", { method: "POST" }).catch(() => null);
        router.push("/");
        router.refresh();
      }}
    >
      Выйти
    </Button>
  );
}
