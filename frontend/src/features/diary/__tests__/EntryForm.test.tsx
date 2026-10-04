import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { EntryForm } from "../EntryForm";

vi.mock("next/link", () => ({ default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a> }));

const tags = [
  { id: "t1", code: "spokoystvie", title: "Спокойствие" },
  { id: "t2", code: "trevoga", title: "Тревога" },
];

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("CL-01 entry form", () => {
  it("asks for a mood before saving", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    render(<EntryForm tags={tags} onSaved={() => undefined} />);

    await userEvent.click(screen.getByRole("button", { name: "Сохранить" }));
    expect(screen.getByText("Выберите, как вы себя чувствуете.")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("sends mood, tags and the private note through the BFF", async () => {
    const entry = { id: "e1", mood: 2, tags: [tags[1]], note: "личное", recorded_at: "2026-10-20T09:00:00Z", local_date: "2026-10-20" };
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: entry }), { status: 201 }));
    vi.stubGlobal("fetch", fetchMock);
    const onSaved = vi.fn();
    render(<EntryForm tags={tags} onSaved={onSaved} />);

    await userEvent.click(screen.getByRole("radio", { name: /Плохо/ }));
    await userEvent.click(screen.getByRole("button", { name: "Тревога" }));
    await userEvent.type(screen.getByLabelText("Заметка для себя"), "личное");
    expect(screen.getByText(/Заметку видите только вы/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Сохранить" }));

    expect(fetchMock).toHaveBeenCalledWith("/bff/v1/diary/entries", expect.objectContaining({ method: "POST" }));
    const body = JSON.parse(fetchMock.mock.calls[0][1].body as string);
    expect(body).toEqual({ mood: 2, tag_ids: ["t2"], note: "личное" });
    expect(onSaved).toHaveBeenCalledWith(entry);
  });

  it("points to emergency help when the mood is very bad", async () => {
    render(<EntryForm tags={tags} onSaved={() => undefined} />);
    await userEvent.click(screen.getByRole("radio", { name: /Очень плохо/ }));
    expect(screen.getByRole("link", { name: "«Экстренная помощь»" })).toHaveAttribute("href", "/help-now");
  });
});
