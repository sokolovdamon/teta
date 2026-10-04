"use client";

import { useState } from "react";
import { ApiError } from "./api";

/** Minimal form state with server validation errors from Laravel. */
export function useForm<T extends Record<string, unknown>>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function set<K extends keyof T>(key: K, value: T[K]) {
    setValues((v) => ({ ...v, [key]: value }));
    setErrors((e) => {
      const next = { ...e };
      delete next[key as string];
      return next;
    });
  }

  async function submit(action: (values: T) => Promise<void>) {
    setSubmitting(true);
    setErrors({});
    setMessage(null);
    try {
      await action(values);
    } catch (e) {
      if (e instanceof ApiError) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.errors)) flat[k] = v[0];
        setErrors(flat);
        setMessage(Object.keys(flat).length ? null : e.message);
      } else {
        setMessage("Не удалось связаться с сервером. Проверьте соединение и попробуйте ещё раз.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return { values, set, errors, message, setMessage, submitting, submit };
}
