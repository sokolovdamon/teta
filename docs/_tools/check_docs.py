#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Проверка согласованности документации ТЕТА.

Проверки:
  1. Относительные ссылки в *.md ([текст](путь), ![alt](путь), [ref]: путь, <a href>/<img src>):
     отсутствующий файл — ERROR; отсутствующий якорь (#заголовок) — WARNING.
  2. Идентификаторы (FR/NFR/BR/INT/Q/DEC/RISK/US): дубли определений и ссылки на
     неопределённые ID — ERROR; ID без ссылок из других файлов — INFO.
     Ссылки ищутся и в *.xlsx (sharedStrings + inline strings).
  3. Сводка открытых вопросов (01_inputs/open_questions.md) по P0/P1/P2.
  4. Маркеры TODO / TBD / ??? / (Q-NN) по файлам.

Использование:
    python3 check_docs.py [--root DOCS_DIR] [--json] [--verbose] [--exclude NAME ...]

Код выхода 1, если есть хотя бы одна ошибка (ERROR). Только стандартная библиотека.

Прагмы в markdown: строка с `<!-- check_docs: ignore -->` пропускается;
блок между `<!-- check_docs: off -->` и `<!-- check_docs: on -->` не проверяется
(удобно для примеров ID в описаниях конвенций).
"""

import argparse
import json
import os
import re
import sys
import unicodedata
import zipfile
import xml.etree.ElementTree as ET
from collections import OrderedDict, defaultdict
from pathlib import Path
from urllib.parse import unquote

# --------------------------------------------------------------------------- #
# Настройки
# --------------------------------------------------------------------------- #

# Тип ID -> (регулярное выражение, описание)
ID_PATTERNS = OrderedDict([
    ("FR", (r"FR-[A-Z0-9]+-\d{3}", "функциональные требования")),
    ("NFR", (r"NFR-[A-Z0-9]+-\d{2,3}", "нефункциональные требования")),
    ("BR", (r"BR-[A-Z0-9]+-\d{2,3}", "бизнес-правила")),
    ("INT", (r"INT-[A-Z0-9]+-\d{2,3}", "интеграции")),
    ("Q", (r"Q-\d{2}", "открытые вопросы")),
    ("DEC", (r"DEC-\d{2}", "решения заказчика")),
    ("RISK", (r"RISK-\d{2}", "риски")),
    ("US", (r"US-[A-Z0-9]+-\d{3}", "user stories")),
])

# Типы ID, для которых первая непустая ячейка строки в .xlsx считается ОПРЕДЕЛЕНИЕМ
# (например, {"US"}, если user stories ведутся только в backlog.xlsx). По умолчанию
# всё, что найдено в .xlsx, — ссылки.
XLSX_DEFINITION_TYPES = {"US"}  # user stories are defined only in 03_product/backlog.xlsx

# Каталоги, которые не сканируются (имя на любом уровне или путь от корня docs).
EXCLUDE_DIRS = {"_tools", ".git", "node_modules", "__pycache__", ".venv", "venv"}

# ID внутри блоков кода ``` игнорируются (там обычно примеры), кроме блоков этих языков
# (в диаграммах ID — настоящие ссылки).
ID_SCAN_CODE_LANGS = {"mermaid"}

OPEN_QUESTIONS_FILE = "01_inputs/open_questions.md"

MARKERS = OrderedDict([
    ("TODO", r"(?<!\w)TODO(?!\w)"),
    ("TBD", r"(?<!\w)TBD(?!\w)"),
    ("???", r"\?\?\?"),
])

MAX_LOCATIONS_SHOWN = 8

# --------------------------------------------------------------------------- #
# Регулярные выражения
# --------------------------------------------------------------------------- #

ID_RE = re.compile(r"(?<![\w-])(%s)(?!\w)" % "|".join(
    "(?:%s)" % p for p, _ in sorted(ID_PATTERNS.values(), key=lambda x: -len(x[0]))))
ID_TYPE_RES = [(t, re.compile(r"^%s$" % p)) for t, (p, _) in ID_PATTERNS.items()]
# «Похоже на ID»: известный префикс и номер в конце (FR-AUTH-01, Q-1); группы вроде BR-B2B не считаются
MALFORMED_RE = re.compile(r"(?<![\w-])((?:%s)(?:-[A-Z0-9]+)*-\d+)(?![\w-])" % "|".join(
    sorted(ID_PATTERNS.keys(), key=len, reverse=True)))
Q_RE = re.compile(r"(?<![\w-])%s(?!\w)" % ID_PATTERNS["Q"][0])

FENCE_RE = re.compile(r"^[ ]{0,3}(`{3,}|~{3,})")
HEADING_RE = re.compile(r"^[ ]{0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$")
INLINE_LINK_RE = re.compile(
    r"(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*"
    r"(<[^>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)"
    r"(?:\s+(?:\"[^\"]*\"|'[^']*'|\([^)]*\)))?\s*\)")
REF_DEF_RE = re.compile(r"^[ ]{0,3}\[([^\]]+)\]:\s*(<[^>]*>|\S+)")
HTML_LINK_RE = re.compile(r"<(?:a|img|source)\b[^>]*?\b(?:href|src)\s*=\s*[\"']([^\"']+)[\"']", re.I)
HTML_ID_RE = re.compile(r"<[a-z][^>]*?\b(?:id|name)\s*=\s*[\"']([^\"']+)[\"']", re.I)
SCHEME_RE = re.compile(r"^[A-Za-z][A-Za-z0-9+.-]*:")
SEPARATOR_ROW_RE = re.compile(r"^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$")
DEF_BOLD_RE = re.compile(r"^\s*(?:>\s*)*(?:[-*+]\s+|\d+[.)]\s+)?(?:\[[ xX]\]\s+)?\*\*\s*([^*]+?)\s*\*\*")
PRAGMA_IGNORE = "check_docs: ignore"
PRAGMA_OFF = "check_docs: off"
PRAGMA_ON = "check_docs: on"
# Заголовок раздела решённых и снятых вопросов в open_questions.md: такие Q не считаются открытыми
RESOLVED_HEADING_RE = re.compile(r"^(?:решённые|решенные|закрытые|снятые)(?!\w)", re.I)


def nfc(s):
    return unicodedata.normalize("NFC", s)


def id_type(ident):
    for t, rx in ID_TYPE_RES:
        if rx.match(ident):
            return t
    return None


class Issues(object):
    def __init__(self):
        self.items = []

    def add(self, level, check, message, file=None, line=None, **extra):
        item = OrderedDict([("level", level), ("check", check), ("file", file), ("line", line), ("message", message)])
        item.update(extra)
        self.items.append(item)
        return item

    def count(self, level, check=None):
        return sum(1 for i in self.items if i["level"] == level and (check is None or i["check"] == check))


# --------------------------------------------------------------------------- #
# Файлы и пути (с учётом NFC/NFD и регистра)
# --------------------------------------------------------------------------- #

class PathResolver(object):
    def __init__(self):
        self._listing = {}

    def _entries(self, directory):
        if directory not in self._listing:
            try:
                names = os.listdir(directory)
            except OSError:
                names = None
            self._listing[directory] = names
        return self._listing[directory]

    def resolve(self, path):
        """Возвращает (реальный_путь | None, регистр_не_совпал: bool)."""
        path = os.path.normpath(path)
        parts = Path(path).parts
        if not parts:
            return None, False
        cur = parts[0]
        case_mismatch = False
        for comp in parts[1:]:
            names = self._entries(cur)
            if names is None:
                return None, False
            want = nfc(comp)
            exact = [n for n in names if nfc(n) == want]
            if exact:
                cur = os.path.join(cur, exact[0])
                continue
            loose = [n for n in names if nfc(n).casefold() == want.casefold()]
            if not loose:
                return None, False
            case_mismatch = True
            cur = os.path.join(cur, loose[0])
        return cur, case_mismatch


def is_excluded(dirpath, name, root, exclude):
    if nfc(name) in exclude or name.startswith("."):
        return True
    rel = nfc(os.path.relpath(os.path.join(dirpath, name), root)).replace(os.sep, "/")
    return rel in exclude


def iter_files(root, exts, exclude):
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = sorted(d for d in dirnames if not is_excluded(dirpath, d, root, exclude))
        for fn in sorted(filenames, key=nfc):
            if fn.startswith((".", "~$")):
                continue
            if fn.lower().endswith(exts):
                yield Path(dirpath) / fn


def rel_path(path, root):
    return nfc(os.path.relpath(str(path), str(root))).replace(os.sep, "/")


# --------------------------------------------------------------------------- #
# Markdown-документ
# --------------------------------------------------------------------------- #

def blank_code_spans(line):
    return re.sub(r"(`+)(.+?)\1", lambda m: " " * len(m.group(0)), line)


def plain_heading_text(text):
    t = re.sub(r"!\[([^\]]*)\]\([^)]*\)", r"\1", text)
    t = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", t)
    t = re.sub(r"<[^>]+>", "", t)
    t = t.replace("`", "")
    t = re.sub(r"(\*\*|__|~~)(.+?)\1", r"\2", t)
    t = re.sub(r"(?<!\w)[*_](.+?)[*_](?!\w)", r"\1", t)
    return t.strip()


def github_slug(text):
    """GitHub-style slug: lower, без пунктуации (кроме - и пробела), пробел -> '-', кириллица сохраняется."""
    t = nfc(plain_heading_text(text)).lower()
    t = re.sub(r"[^\w\- ]", "", t)
    return t.replace(" ", "-")


class MdDoc(object):
    def __init__(self, path, root):
        self.path = Path(path)
        self.rel = rel_path(path, root)
        with open(str(path), "r", encoding="utf-8-sig", errors="replace") as fh:
            text = fh.read()
        self.lines = text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
        n = len(self.lines)
        self.code = [False] * n
        self.code_lang = [None] * n
        self.skip = [False] * n
        self.headings = []  # (lineno, level, raw)
        self.anchors = set()
        fence = None
        lang = None
        off = False
        slug_seen = defaultdict(int)
        for i, line in enumerate(self.lines):
            if PRAGMA_OFF in line:
                off = True
            self.skip[i] = off or PRAGMA_IGNORE in line
            if PRAGMA_ON in line:
                off = False
                self.skip[i] = True
            if fence:
                self.code[i] = True
                self.code_lang[i] = lang
                st = line.strip()
                if st and set(st) == {fence[0]} and len(st) >= len(fence):
                    fence = None
                continue
            fm = FENCE_RE.match(line)
            if fm:
                fence = fm.group(1)
                lang = (line.strip()[len(fence):].strip().split() or [""])[0].lower()
                self.code[i] = True
                self.code_lang[i] = lang
                continue
            hm = HEADING_RE.match(line)
            if hm:
                raw = hm.group(2) or ""
                self.headings.append((i + 1, len(hm.group(1)), raw))
                base = github_slug(raw)
                k = slug_seen[base]
                slug_seen[base] += 1
                self.anchors.add(base if k == 0 else "%s-%d" % (base, k))
            for am in HTML_ID_RE.finditer(line):
                self.anchors.add(nfc(am.group(1)).lower())

    def has_anchor(self, anchor):
        return nfc(anchor).lower() in self.anchors


# --------------------------------------------------------------------------- #
# 1. Ссылки
# --------------------------------------------------------------------------- #

def extract_links(doc):
    for i, line in enumerate(doc.lines):
        if doc.code[i] or doc.skip[i]:
            continue
        s = blank_code_spans(line)
        for m in INLINE_LINK_RE.finditer(s):
            yield i + 1, m.group(3)
        m = REF_DEF_RE.match(s)
        if m and not m.group(1).startswith("^"):
            yield i + 1, m.group(2)
        for m in HTML_LINK_RE.finditer(s):
            yield i + 1, m.group(1)


def check_links(docs, root, resolver, issues):
    index = {nfc(os.path.realpath(str(d.path))): d for d in docs}
    stats = OrderedDict([("checked", 0), ("anchors_checked", 0)])
    for doc in docs:
        for lineno, target in extract_links(doc):
            t = target.strip()
            if t.startswith("<") and t.endswith(">"):
                t = t[1:-1].strip()
            if not t or SCHEME_RE.match(t) or t.startswith("//"):
                continue
            path_part, _, anchor = t.partition("#")
            path_part = unquote(path_part.split("?", 1)[0])
            anchor = unquote(anchor)
            if not path_part:
                if anchor:
                    stats["anchors_checked"] += 1
                    if not doc.has_anchor(anchor):
                        issues.add("WARNING", "links", "якорь #%s не найден в этом файле" % anchor,
                                   doc.rel, lineno, target=target)
                continue
            stats["checked"] += 1
            if path_part.startswith("/"):
                candidate = os.path.join(str(root.parent), path_part.lstrip("/"))
            else:
                candidate = os.path.join(str(doc.path.parent), path_part)
            real, case_mismatch = resolver.resolve(candidate)
            if real is None:
                issues.add("ERROR", "links", "битая ссылка «%s»: не найден %s" % (target, rel_path(os.path.normpath(candidate), root)),
                           doc.rel, lineno, target=target)
                continue
            if case_mismatch:
                issues.add("WARNING", "links", "регистр имени в ссылке «%s» не совпадает с файлом %s" % (target, rel_path(real, root)),
                           doc.rel, lineno, target=target)
            if anchor and real.lower().endswith(".md") and os.path.isfile(real):
                stats["anchors_checked"] += 1
                key = nfc(os.path.realpath(real))
                tdoc = index.get(key)
                if tdoc is None:
                    try:
                        tdoc = index[key] = MdDoc(real, root)
                    except OSError:
                        continue
                if not tdoc.has_anchor(anchor):
                    issues.add("WARNING", "links", "якорь #%s не найден в %s" % (anchor, tdoc.rel),
                               doc.rel, lineno, target=target)
    return stats


# --------------------------------------------------------------------------- #
# 2. Идентификаторы
# --------------------------------------------------------------------------- #

def split_table_cells(line):
    s = line.strip().replace("\\|", "\x00")
    if s.startswith("|"):
        s = s[1:]
    if s.endswith("|"):
        s = s[:-1]
    return [c.replace("\x00", "|").strip() for c in s.split("|")]


def strip_decor(text):
    c = re.sub(r"^<a\b[^>]*>\s*</a>\s*", "", text.strip(), flags=re.I)
    return re.sub(r"^[*_`~\[]+", "", c)


def definition_on_line(line):
    """ID, определяемый в строке: 1-я непустая ячейка таблицы, начало заголовка, **ID** в начале строки/пункта."""
    s = line.strip()
    if s.startswith("|"):
        if SEPARATOR_ROW_RE.match(s):
            return None
        first = next((c for c in split_table_cells(s) if c), None)
        if first is None:
            return None
        c = strip_decor(first)
        m = ID_RE.match(c)
        if m and not ID_RE.search(c, m.end()):
            return m.group(1)
        return None
    hm = HEADING_RE.match(line)
    if hm:
        m = ID_RE.match(strip_decor(hm.group(2) or ""))
        return m.group(1) if m else None
    bm = DEF_BOLD_RE.match(line)
    if bm:
        inner = bm.group(1)
        m = ID_RE.match(inner)
        if m and not inner[m.end():].strip(" .:"):
            return m.group(1)
    return None


def _si_text(el):
    parts = []
    for child in el:
        if child.tag == XNS + "t":
            parts.append(child.text or "")
        elif child.tag == XNS + "r":
            parts.extend(t.text or "" for t in child.iter(XNS + "t"))
    return "".join(parts)


XNS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
XRNS = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
XPKG = "{http://schemas.openxmlformats.org/package/2006/relationships}"


def read_xlsx_cells(path):
    """Список (лист, ячейка, первая_непустая_в_строке, текст) для строковых ячеек."""
    import posixpath
    out = []
    with zipfile.ZipFile(str(path)) as z:
        names = set(z.namelist())
        shared = []
        if "xl/sharedStrings.xml" in names:
            shared = [_si_text(si) for si in ET.fromstring(z.read("xl/sharedStrings.xml")).iter(XNS + "si")]
        sheets = []
        if "xl/workbook.xml" in names and "xl/_rels/workbook.xml.rels" in names:
            rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))
            targets = {r.get("Id"): r.get("Target") for r in rels.iter(XPKG + "Relationship")}
            for sh in ET.fromstring(z.read("xl/workbook.xml")).iter(XNS + "sheet"):
                tgt = targets.get(sh.get(XRNS + "id"))
                if not tgt:
                    continue
                tgt = posixpath.normpath(tgt.lstrip("/") if tgt.startswith("/") else "xl/" + tgt)
                if tgt in names:
                    sheets.append((sh.get("name") or tgt, tgt))
        if not sheets:
            sheets = [(posixpath.splitext(posixpath.basename(n))[0], n)
                      for n in sorted(names) if re.match(r"^xl/worksheets/[^/]+\.xml$", n)]
        for sheet_name, member in sheets:
            for row in ET.fromstring(z.read(member)).iter(XNS + "row"):
                first = True
                for c in row.iter(XNS + "c"):
                    t = c.get("t")
                    v = c.find(XNS + "v")
                    text = None
                    if t == "s" and v is not None and (v.text or "").strip().isdigit():
                        idx = int(v.text)
                        text = shared[idx] if idx < len(shared) else None
                    elif t == "inlineStr":
                        isel = c.find(XNS + "is")
                        text = _si_text(isel) if isel is not None else None
                    elif t == "str" and v is not None:
                        text = v.text
                    elif v is not None and (v.text or "").strip():
                        first = False
                        continue
                    if not text or not text.strip():
                        continue
                    out.append((sheet_name, c.get("r") or "?", first, text))
                    first = False
    return out


def check_ids(docs, xlsx_files, root, issues):
    defs = defaultdict(list)   # id -> [(file, line)]
    refs = defaultdict(list)   # id -> [(file, line)]
    for doc in docs:
        for i, line in enumerate(doc.lines):
            if doc.skip[i] or (doc.code[i] and doc.code_lang[i] not in ID_SCAN_CODE_LANGS):
                continue
            matches = list(ID_RE.finditer(line))
            def_id = definition_on_line(line) if matches and not doc.code[i] else None
            for m in matches:
                ident = m.group(1)
                if def_id == ident:
                    defs[ident].append((doc.rel, i + 1))
                    def_id = None  # только первое вхождение — определение
                else:
                    refs[ident].append((doc.rel, i + 1))
            if not doc.code[i]:
                for m in MALFORMED_RE.finditer(blank_code_spans(line)):
                    cand = m.group(1)
                    if not ID_RE.fullmatch(cand) and not ID_RE.match(cand):
                        issues.add("WARNING", "ids", "похоже на ID, но формат неверный: %s" % cand, doc.rel, i + 1, id=cand)

    xlsx_stats = OrderedDict()
    for path in xlsx_files:
        rel = rel_path(path, root)
        try:
            cells = read_xlsx_cells(path)
        except (zipfile.BadZipFile, KeyError, ET.ParseError, ValueError, OSError) as exc:
            issues.add("WARNING", "ids", "не удалось прочитать xlsx: %s" % exc, rel)
            continue
        found = 0
        for sheet, ref, first, text in cells:
            for k, m in enumerate(ID_RE.finditer(text)):
                ident = m.group(1)
                loc = "%s [%s!%s]" % (rel, sheet, ref)
                found += 1
                if first and k == 0 and m.start() == len(text) - len(text.lstrip()) \
                        and id_type(ident) in XLSX_DEFINITION_TYPES:
                    defs[ident].append((loc, None))
                else:
                    refs[ident].append((loc, None))
        xlsx_stats[rel] = found

    def fmt(locs):
        return ["%s:%d" % (f, ln) if ln else f for f, ln in locs]

    for ident in sorted(defs):
        if len(defs[ident]) > 1:
            issues.add("ERROR", "ids", "дубль определения %s (%d раза)" % (ident, len(defs[ident])),
                       defs[ident][0][0], defs[ident][0][1], id=ident, locations=fmt(defs[ident]))
    for ident in sorted(refs):
        if ident not in defs:
            issues.add("ERROR", "ids", "ссылка на неопределённый ID %s" % ident,
                       refs[ident][0][0], refs[ident][0][1], id=ident, locations=fmt(refs[ident]))

    def base_file(loc):
        return loc.split(" [", 1)[0]

    unreferenced = defaultdict(list)
    for ident, locs in defs.items():
        own = {base_file(f) for f, _ in locs}
        if not any(base_file(f) not in own for f, _ in refs.get(ident, [])):
            unreferenced[id_type(ident)].append(ident)
    by_type = OrderedDict()
    for t in ID_PATTERNS:
        by_type[t] = OrderedDict([
            ("defined", sum(1 for x in defs if id_type(x) == t)),
            ("references", sum(len(v) for x, v in refs.items() if id_type(x) == t)),
            ("unreferenced_outside_own_file", sorted(unreferenced.get(t, []))),
        ])
    total_unref = sum(len(v) for v in unreferenced.values())
    if total_unref:
        issues.add("INFO", "ids", "определены, но нет ссылок из других файлов: %d (%s)" % (
            total_unref, ", ".join("%s %d" % (t, len(unreferenced[t])) for t in ID_PATTERNS if unreferenced.get(t))))
    return OrderedDict([("by_type", by_type), ("xlsx_references", xlsx_stats)])


# --------------------------------------------------------------------------- #
# 3. Открытые вопросы
# --------------------------------------------------------------------------- #

def check_open_questions(root, resolver, issues, docs_by_rel):
    real, _ = resolver.resolve(str(root / OPEN_QUESTIONS_FILE))
    result = OrderedDict([("file", OPEN_QUESTIONS_FILE), ("found", bool(real))])
    if not real:
        issues.add("WARNING", "open_questions", "файл открытых вопросов не найден", OPEN_QUESTIONS_FILE)
        return result
    doc = docs_by_rel.get(rel_path(real, root)) or MdDoc(real, root)
    sections = OrderedDict((p, []) for p in ("P0", "P1", "P2"))
    resolved = []
    other, by_column, declared = [], [], OrderedDict()
    current, p_level = None, 0
    for i, line in enumerate(doc.lines):
        if doc.code[i]:
            continue
        hm = HEADING_RE.match(line)
        if hm:
            level = len(hm.group(1))
            pm = re.search(r"(?<!\w)P([0-2])(?!\w)", hm.group(2) or "")
            if RESOLVED_HEADING_RE.match(plain_heading_text(hm.group(2) or "")):
                current, p_level = "RESOLVED", level
            elif pm:
                current, p_level = "P" + pm.group(1), level
            elif current is None or level <= p_level:
                current, p_level = None, 0
            continue
        s = line.strip()
        if not s.startswith("|") or SEPARATOR_ROW_RE.match(s):
            continue
        cells = split_table_cells(s)
        d = definition_on_line(line)
        if d and id_type(d) == "Q":
            if current == "RESOLVED":
                resolved.append(d)
                continue
            if current:
                sections[current].append(d)
                continue
            # вне P-раздела — ищем колонку приоритета в самой строке (| Q-36 | P1 | ...)
            pri = next((c.strip("* ") for c in cells[1:] if re.fullmatch(r"\**P[0-2]\**", c.strip())), None)
            if pri:
                sections[pri].append(d)
                by_column.append(d)
            else:
                other.append(d)
            continue
        first = strip_decor(cells[0] if cells else "").strip("* ")
        if current is None and re.fullmatch(r"P[0-2]", first) and len(cells) >= 2 and cells[1].strip("* ").isdigit():
            declared[first] = int(cells[1].strip("* "))

    counts = OrderedDict((p, len(v)) for p, v in sections.items())
    all_q = [q for v in sections.values() for q in v] + other + resolved
    nums = sorted({int(q[2:]) for q in all_q})
    missing = ["Q-%02d" % n for n in range(1, nums[-1] + 1) if n not in set(nums)] if nums else []
    result.update([("by_priority", counts), ("by_priority_column_outside_sections", by_column),
                   ("without_priority", len(other)), ("resolved", len(resolved)), ("total", len(all_q)),
                   ("declared_in_summary", declared), ("numbering_gaps", missing)])
    for p, n in declared.items():
        if counts.get(p) != n:
            issues.add("WARNING", "open_questions", "сводная таблица: %s = %d, фактически в разделе %d" % (p, n, counts.get(p, 0)),
                       doc.rel)
    if other:
        issues.add("WARNING", "open_questions", "вопросы вне разделов P0/P1/P2: %s" % ", ".join(other), doc.rel)
    if missing:
        issues.add("INFO", "open_questions", "пропуски в нумерации: %s" % ", ".join(missing), doc.rel)
    return result


# --------------------------------------------------------------------------- #
# 4. Маркеры
# --------------------------------------------------------------------------- #

MARKER_RES = [(name, re.compile(rx)) for name, rx in MARKERS.items()]
QREF_KEY = "(Q-NN)"


def count_markers(docs):
    keys = list(MARKERS) + [QREF_KEY]
    totals = OrderedDict((k, 0) for k in keys)
    rows = []
    for doc in docs:
        counts = OrderedDict((k, 0) for k in keys)
        for line in doc.lines:
            for name, rx in MARKER_RES:
                counts[name] += len(rx.findall(line))
            for grp in re.findall(r"\(([^()]*)\)", line):
                counts[QREF_KEY] += len(Q_RE.findall(grp))
        if any(counts.values()):
            row = OrderedDict([("file", doc.rel)])
            row.update(counts)
            rows.append(row)
            for k in keys:
                totals[k] += counts[k]
    return OrderedDict([("files", rows), ("totals", totals)])


# --------------------------------------------------------------------------- #
# Отчёт
# --------------------------------------------------------------------------- #

def _loc(item):
    if item.get("file") and item.get("line"):
        return "%s:%d" % (item["file"], item["line"])
    return item.get("file") or ""


def _print_issues(issues, check, verbose):
    order = {"ERROR": 0, "WARNING": 1, "INFO": 2}
    items = sorted((i for i in issues.items if i["check"] == check), key=lambda i: order[i["level"]])
    for it in items:
        locs = it.get("locations")
        if locs:
            shown = locs if verbose else locs[:MAX_LOCATIONS_SHOWN]
            more = "" if len(shown) == len(locs) else " … ещё %d" % (len(locs) - len(shown))
            print("  %-7s %s: %s%s" % (it["level"], it["message"], ", ".join(shown), more))
        else:
            loc = _loc(it)
            print("  %-7s %s%s" % (it["level"], (loc + "  ") if loc else "", it["message"]))


def print_text(report, issues, verbose):
    f = report["files"]
    print("Проверка документации: %s" % report["root"])
    print("Файлов: md %d, xlsx %d; исключены каталоги: %s" % (f["md"], f["xlsx"], ", ".join(f["excluded_dirs"])))

    ls = report["links"]
    print("\n== 1. Относительные ссылки ==")
    print("Проверено ссылок на файлы: %d, якорей: %d; ошибок: %d, предупреждений: %d" % (
        ls["checked"], ls["anchors_checked"], issues.count("ERROR", "links"), issues.count("WARNING", "links")))
    _print_issues(issues, "links", verbose)

    ids = report["ids"]
    print("\n== 2. Идентификаторы ==")
    print("  %-5s %-30s %9s %7s %22s" % ("Тип", "Описание", "Определено", "Ссылок", "Без внешних ссылок"))
    for t, st in ids["by_type"].items():
        print("  %-5s %-30s %9d %7d %22d" % (t, ID_PATTERNS[t][1], st["defined"], st["references"],
                                            len(st["unreferenced_outside_own_file"])))
    if ids["xlsx_references"]:
        print("  xlsx: " + "; ".join("%s — ID в ячейках: %d" % kv for kv in ids["xlsx_references"].items()))
    else:
        print("  xlsx: файлов нет")
    print("Ошибок: %d, предупреждений: %d" % (issues.count("ERROR", "ids"), issues.count("WARNING", "ids")))
    _print_issues(issues, "ids", verbose)
    if verbose:
        for t, st in ids["by_type"].items():
            if st["unreferenced_outside_own_file"]:
                print("  INFO    %s без ссылок из других файлов: %s" % (t, ", ".join(st["unreferenced_outside_own_file"])))

    oq = report["open_questions"]
    print("\n== 3. Открытые вопросы (%s) ==" % oq["file"])
    if oq["found"]:
        print("  " + "  ".join("%s: %d" % kv for kv in oq["by_priority"].items())
              + "  без приоритета: %d  решено или снято: %d  всего: %d" % (
                  oq["without_priority"], oq["resolved"], oq["total"]))
        if oq["by_priority_column_outside_sections"]:
            print("  в т.ч. вне разделов P0/P1/P2, по колонке приоритета: %s"
                  % ", ".join(oq["by_priority_column_outside_sections"]))
        if oq["declared_in_summary"]:
            print("  В сводной таблице: " + "  ".join("%s: %d" % kv for kv in oq["declared_in_summary"].items()))
    _print_issues(issues, "open_questions", verbose)

    mk = report["markers"]
    print("\n== 4. Маркеры ==")
    keys = list(mk["totals"].keys())
    if not mk["files"]:
        print("  Маркеров не найдено.")
    else:
        width = max([len("Файл"), len("Итого")] + [len(r["file"]) for r in mk["files"]])
        print("  " + "Файл".ljust(width) + "".join(k.rjust(8) for k in keys))
        for r in mk["files"]:
            print("  " + r["file"].ljust(width) + "".join(str(r[k]).rjust(8) for k in keys))
        print("  " + "Итого".ljust(width) + "".join(str(mk["totals"][k]).rjust(8) for k in keys))

    if issues.count("ERROR", "files"):
        print("\n== Файлы ==")
        _print_issues(issues, "files", verbose)

    s = report["summary"]
    print("\n== Итог ==")
    print("ERROR: %d, WARNING: %d, INFO: %d → %s" % (
        s["errors"], s["warnings"], s["info"], "есть ошибки (код 1)" if s["errors"] else "ошибок нет (код 0)"))


def main(argv=None):
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8")
    default_root = Path(__file__).resolve().parent.parent
    ap = argparse.ArgumentParser(description="Проверка согласованности документации ТЕТА.")
    ap.add_argument("--root", default=str(default_root), help="папка docs (по умолчанию %(default)s)")
    ap.add_argument("--json", action="store_true", help="машиночитаемый отчёт в JSON")
    ap.add_argument("-v", "--verbose", action="store_true", help="все места и списки ID без внешних ссылок")
    ap.add_argument("--exclude", action="append", default=[], metavar="NAME",
                    help="дополнительно исключить каталог (имя или путь от корня), можно повторять")
    args = ap.parse_args(argv)

    root = Path(args.root).resolve()
    if not root.is_dir():
        print("ERROR: папка не найдена: %s" % root, file=sys.stderr)
        return 2
    exclude = set(EXCLUDE_DIRS) | {nfc(x.strip("/")) for x in args.exclude}
    issues = Issues()
    resolver = PathResolver()
    docs = []
    for p in iter_files(str(root), (".md", ".markdown"), exclude):
        try:
            docs.append(MdDoc(p, root))
        except OSError as exc:
            issues.add("ERROR", "files", "не удалось прочитать файл: %s" % exc, rel_path(p, root))
    xlsx_files = list(iter_files(str(root), (".xlsx", ".xlsm"), exclude))

    link_stats = check_links(docs, root, resolver, issues)
    id_stats = check_ids(docs, xlsx_files, root, issues)
    oq = check_open_questions(root, resolver, issues, {d.rel: d for d in docs})
    markers = count_markers(docs)

    report = OrderedDict([
        ("root", nfc(str(root))),
        ("files", OrderedDict([("md", len(docs)), ("xlsx", len(xlsx_files)), ("excluded_dirs", sorted(exclude))])),
        ("summary", OrderedDict([("errors", issues.count("ERROR")), ("warnings", issues.count("WARNING")),
                                 ("info", issues.count("INFO"))])),
        ("links", link_stats),
        ("ids", id_stats),
        ("open_questions", oq),
        ("markers", markers),
        ("issues", issues.items),
    ])
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print_text(report, issues, args.verbose)
    return 1 if issues.count("ERROR") else 0


if __name__ == "__main__":
    sys.exit(main())
