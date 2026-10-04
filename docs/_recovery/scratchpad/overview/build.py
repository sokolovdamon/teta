# -*- coding: utf-8 -*-
"""Assemble docs/00_plan/package_overview.html from template, fragments and customer logo files."""
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(os.path.dirname(HERE), "brand")
OUT = "/Users/dmitry/Projects/teta_new/docs/00_plan/package_overview.html"


def read(name, default=""):
    path = os.path.join(HERE, name)
    if not os.path.exists(path):
        return default
    with open(path, encoding="utf-8") as fh:
        return fh.read().strip()


html = read("template.html")
subs = {
    "LOGO_BLACK": open(os.path.join(BRAND, "logo_black_430.b64")).read().strip(),
    "LOGO_WHITE": open(os.path.join(BRAND, "logo_white_430.b64")).read().strip(),
    "SCOPE_EXTRA": read("scope_extra.html"),
    "ROADMAP": read("roadmap.html"),
    "RISKS": read("risks.html"),
    "PACKAGE": read("package.html"),
}
for key, value in subs.items():
    html = html.replace("{{%s}}" % key, value)
left = re.findall(r"\{\{[A-Z_]+\}\}", html)
if left:
    sys.exit("unfilled placeholders: %s" % ", ".join(sorted(set(left))))
for bad in ("Oswald", "JetBrains", "MVP", "R2", "R3", "Jost"):
    if bad in html:
        print("WARNING: found", bad)
with open(OUT, "w", encoding="utf-8") as fh:
    fh.write(html + "\n")
print("written", OUT, len(html), "chars")
