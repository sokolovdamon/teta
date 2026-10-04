"use client";

import { useCallback, useEffect, useId, useState } from "react";
import { Alert, Button, Card, EmptyState, Field, Select, Textarea } from "@/components/ui";
import { ApiError, api } from "@/lib/api";
import { dateTime } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import type { Note, SessionRow } from "./types";

const MAX = 10000;

/** PRO-05: private notes. Visible only to the author — not to the client, other specialists or administrators. */
export function NotesTab({ clientId, timezone, sessions }: { clientId: string; timezone: string; sessions: SessionRow[] }) {
  const [notes, setNotes] = useState<Note[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [body, setBody] = useState("");
  const [sessionId, setSessionId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<{ id: string; body: string } | null>(null);
  const textareaId = useId();

  const load = useCallback(
    () =>
      api<Paginated<Note>>(`/pro/clients/${clientId}/notes`)
        .then((r) => {
          setNotes(r.data);
          setFailed(false);
        })
        .catch(() => setFailed(true)),
    [clientId],
  );

  useEffect(() => {
    void load();
  }, [load]);

  async function add() {
    if (!body.trim()) {
      setError("Напишите текст заметки.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await api(`/pro/clients/${clientId}/notes`, { method: "POST", body: { body: body.trim(), session_id: sessionId || null } });
      setBody("");
      setSessionId("");
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : "Не удалось сохранить заметку.");
    } finally {
      setSaving(false);
    }
  }

  async function saveEdit() {
    if (!editing || !editing.body.trim()) return;
    try {
      await api(`/pro/clients/${clientId}/notes/${editing.id}`, { method: "PATCH", body: { body: editing.body.trim() } });
      setEditing(null);
      await load();
    } catch {
      setError("Не удалось сохранить изменения.");
    }
  }

  async function remove(id: string) {
    if (!window.confirm("Удалить заметку? Восстановить её будет нельзя.")) return;
    await api(`/pro/clients/${clientId}/notes/${id}`, { method: "DELETE" });
    await load();
  }

  const heldSessions = sessions.filter((s) => s.status === "held");

  return (
    <div className="grid gap-4">
      <Alert tone="info" title="🔒 Заметки видите только вы">
        Их не видят клиент, другие специалисты и администраторы платформы. Заметки хранятся в зашифрованном виде и удаляются через установленный срок после
        окончания работы с клиентом.
      </Alert>

      <Card>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            void add();
          }}
        >
          <label htmlFor={textareaId} className="text-sm font-medium text-ink-2">
            Новая заметка
          </label>
          <Textarea id={textareaId} value={body} maxLength={MAX} onChange={(e) => setBody(e.target.value)} placeholder="Наблюдения, гипотезы, план следующей встречи" rows={5} />
          <div className="flex flex-wrap items-end justify-between gap-3">
            {heldSessions.length > 0 ? (
              <Field label="К сессии (необязательно)" className="min-w-56">
                <Select value={sessionId} onChange={(e) => setSessionId(e.target.value)}>
                  <option value="">Без привязки</option>
                  {heldSessions.map((s) => (
                    <option key={s.id} value={s.id}>
                      {dateTime(s.starts_at, timezone)}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : (
              <span />
            )}
            <Button type="submit" loading={saving}>
              Сохранить заметку
            </Button>
          </div>
          {error && <Alert tone="danger">{error}</Alert>}
        </form>
      </Card>

      {failed && <Alert tone="danger">Не удалось загрузить заметки. Обновите страницу.</Alert>}
      {notes === null && !failed && <p className="text-muted">Загрузка…</p>}
      {notes && notes.length === 0 && <EmptyState title="Заметок пока нет" description="Записывайте наблюдения после сессий — они останутся только у вас." />}
      {notes && notes.length > 0 && (
        <ul className="grid gap-3">
          {notes.map((n) => (
            <li key={n.id}>
              <Card className="grid gap-2">
                <p className="text-sm text-muted">
                  {dateTime(n.created_at, timezone)}
                  {n.session_starts_at && ` · к сессии ${dateTime(n.session_starts_at, timezone)}`}
                  {n.updated_at !== n.created_at && " · изменена"}
                </p>
                {editing?.id === n.id ? (
                  <div className="grid gap-2">
                    <Textarea value={editing.body} maxLength={MAX} onChange={(e) => setEditing({ id: n.id, body: e.target.value })} rows={5} aria-label="Текст заметки" />
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>
                        Отмена
                      </Button>
                      <Button size="sm" onClick={() => void saveEdit()}>
                        Сохранить
                      </Button>
                    </div>
                  </div>
                ) : (
                  <>
                    <p className="whitespace-pre-line">{n.body}</p>
                    <div className="flex gap-3 text-sm">
                      <button type="button" className="text-brand" onClick={() => setEditing({ id: n.id, body: n.body })}>
                        Изменить
                      </button>
                      <button type="button" className="text-muted hover:text-danger" onClick={() => void remove(n.id)}>
                        Удалить
                      </button>
                    </div>
                  </>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
