# -*- coding: utf-8 -*-
"""Generate docs/06_architecture/diagrams/README.md (v2.1) by matching .mmd sources to mermaid blocks in docs."""
import glob
import os
import re

DOCS = "/Users/dmitry/Projects/teta_new/docs/"
DIAG = DOCS + "06_architecture/diagrams/"
SOURCES = [
    ("06_architecture/product_architecture.md", "Архитектура продукта"),
    ("06_architecture/technical_architecture.md", "Техническая архитектура"),
    ("06_architecture/data_model.md", "Модель данных"),
    ("06_architecture/sequences_states.md", "Последовательности и состояния"),
]
HEADING = re.compile(r"^(#{2,4})\s+(.*)$")


def norm(s):
    return re.sub(r"\s+", " ", s.strip())


blocks = []  # (normalized content, doc, section title)
for rel, _title in SOURCES:
    lines = open(DOCS + rel, encoding="utf-8").read().split("\n")
    last_heading = ""
    i = 0
    while i < len(lines):
        hm = HEADING.match(lines[i])
        if hm:
            last_heading = re.sub(r"`", "", hm.group(2)).strip()
        if lines[i].strip().startswith("```mermaid"):
            j = i + 1
            buf = []
            while j < len(lines) and not lines[j].strip().startswith("```"):
                buf.append(lines[j])
                j += 1
            blocks.append((norm("\n".join(buf)), rel, last_heading))
            i = j
        i += 1

# Titles from registry tables in sequences_states.md and data_model.md (16.4)
table_titles = {}
for rel in ("06_architecture/sequences_states.md", "06_architecture/data_model.md", "06_architecture/product_architecture.md", "06_architecture/technical_architecture.md"):
    for line in open(DOCS + rel, encoding="utf-8").read().split("\n"):
        if not line.startswith("|"):
            continue
        m = re.search(r"\[([a-z0-9_]+)\]\(diagrams/\1\.mmd\)", line)
        if not m:
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        name = m.group(1)
        texts = [c for c in cells if name not in c and not re.fullmatch(r"(SEQ|ST)-\d{2}|\d+(\.\d+)?|[A-Z, ]+|Новая.*|Обновлена.*", c)]
        if texts:
            table_titles.setdefault(name, texts[0])

GROUPS = [
    ("Архитектура продукта", lambda n: n.startswith("product_")),
    ("Техническая архитектура", lambda n: n.startswith(("c4_", "tech_")) or n in ("backend_modules", "cicd_pipeline", "outbox_flow", "llm_gateway_pipeline")),
    ("Модель данных", lambda n: n.startswith("dm_")),
    ("Последовательности", lambda n: n.startswith("seq_")),
    ("Машины состояний", lambda n: n.startswith("st_")),
]

names = sorted(os.path.basename(p)[:-4] for p in glob.glob(DIAG + "*.mmd"))
unmatched = []
out = ["# Диаграммы архитектуры ТЕТА", "",
       "> Версия 2.1 · 11.09.2026 · Исходники Mermaid (`.mmd`) и рендеры SVG.",
       "> Изменения v2: набор диаграмм пересобран под реестр решений 2.1 — TetaMeet, отдельные инстансы white-label, баланс клиента, супервизия и интервизия, email-маркетинг, техподдержка; рендеры выполнены в фирменном оформлении (шрифт Onest, цвета `#4D427A` и `#4A4A4A`).", "",
       "Источник истины — блок `mermaid` в документе; файл `.mmd` повторяет его содержимое. После правки блока обновите `.mmd` и пересоберите SVG:", "",
       "```bash", "docs/_tools/render_diagrams.sh", "```", "",
       "Скрипт использует mermaid-cli 11 и конфигурацию [`_tools/mermaid_brand.json`](../../_tools/mermaid_brand.json); аргумент — регулярное выражение имён, например `'^seq_'`.", ""]
total = 0
for group, pred in GROUPS:
    items = [n for n in names if pred(n)]
    if not items:
        continue
    out += ["## %s (%d)" % (group, len(items)), "", "| Диаграмма | Что показывает | Где описана | Рендер |", "|---|---|---|---|"]
    for n in items:
        content = norm(open(DIAG + n + ".mmd", encoding="utf-8").read())
        where = next(((rel, sec) for c, rel, sec in blocks if c == content), None)
        title = table_titles.get(n)
        if where:
            rel, sec = where
            doc_title = dict(SOURCES)[rel]
            place = "[%s](../%s), «%s»" % (doc_title, os.path.basename(rel), sec.replace("|", "/"))
            title = title or sec
        else:
            place = "—"
            unmatched.append(n)
        title = (title or n).replace("|", "/")
        svg = "[SVG](%s.svg)" % n if os.path.exists(DIAG + n + ".svg") else "нет"
        out.append("| [%s](%s.mmd) | %s | %s | %s |" % (n, n, title, place, svg))
        total += 1
    out.append("")
out.insert(4, "> Всего диаграмм: %d." % total)
open(DIAG + "README.md", "w", encoding="utf-8").write("\n".join(out) + "\n")
print("diagrams:", total, "unmatched to doc blocks:", unmatched)
