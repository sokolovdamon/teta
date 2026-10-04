"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, api, buildQuery } from "@/lib/api";

type Query = Parameters<typeof buildQuery>[0];

/**
 * Loads a JSON resource through the BFF with loading / error state. reload() refetches and keeps the previous data
 * on screen while loading; setData() applies an optimistic or returned value.
 */
export function useResource<T>(path: string | null, query?: Query) {
  const key = path ? `${path}${buildQuery(query)}` : null;
  const [version, setVersion] = useState(0);
  const [state, setState] = useState<{ token: string | null; data: T | null; error: string | null }>({ token: null, data: null, error: null });
  const token = key ? `${key}#${version}` : null;

  useEffect(() => {
    if (!key) return;
    let cancelled = false;
    const current = `${key}#${version}`;
    api<T>(key).then(
      (data) => {
        if (!cancelled) setState({ token: current, data, error: null });
      },
      (e: unknown) => {
        if (!cancelled) setState((s) => ({ token: current, data: s.data, error: errorMessage(e, "Не удалось загрузить данные. Проверьте соединение.") }));
      },
    );
    return () => {
      cancelled = true;
    };
  }, [key, version]);

  const reload = useCallback(() => setVersion((v) => v + 1), []);
  const setData = useCallback((data: T) => setState((s) => ({ ...s, data })), []);
  const loading = token !== null && state.token !== token;

  return { data: state.data, error: loading ? null : state.error, loading, reload, setData };
}

/** Message of a failed API call for an inline alert: the first validation error or the server message. */
export function errorMessage(e: unknown, fallback = "Не удалось выполнить действие."): string {
  if (e instanceof ApiError) {
    const first = Object.values(e.errors)[0]?.[0];
    return first ?? e.message ?? fallback;
  }
  return fallback;
}
