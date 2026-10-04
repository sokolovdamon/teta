import { describe, expect, it } from "vitest";
import { clientStatusLabel, fileSize, isInternalLink, isValidLinkUrl, linkTitle } from "../labels";

describe("recommendation labels", () => {
  it("shows DM-12 statuses, a sent one is «Новая» for the client", () => {
    expect(clientStatusLabel("sent")).toBe("Новая");
    expect(clientStatusLabel("viewed")).toBe("Просмотрена");
    expect(clientStatusLabel("done")).toBe("Выполнена");
  });

  it("accepts only http(s) links and platform paths", () => {
    expect(isValidLinkUrl("https://example.org/a")).toBe(true);
    expect(isValidLinkUrl("/client/materials/dyhanie")).toBe(true);
    expect(isValidLinkUrl("javascript:alert(1)")).toBe(false);
    expect(isValidLinkUrl("//evil.example")).toBe(false);
    expect(isValidLinkUrl("ftp://x")).toBe(false);
  });

  it("titles links and tells platform pages apart", () => {
    expect(isInternalLink({ url: "/client/materials/x" })).toBe(true);
    expect(isInternalLink({ url: "https://teta.su" })).toBe(false);
    expect(linkTitle({ url: "https://www.example.org/x", title: null, kb_material_id: null })).toBe("example.org");
    expect(linkTitle({ url: "/client/materials/x", title: null, kb_material_id: "id" })).toBe("Материал из базы знаний");
    expect(linkTitle({ url: "https://a.b", title: "Видео", kb_material_id: null })).toBe("Видео");
  });

  it("formats file sizes", () => {
    expect(fileSize(500)).toBe("1 КБ");
    expect(fileSize(200 * 1024)).toBe("200 КБ");
    expect(fileSize(3.5 * 1024 * 1024)).toBe("3,5 МБ");
  });
});
