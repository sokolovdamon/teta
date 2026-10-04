---
name: teta-project-inputs
description: "Where TETA source materials live and gotchas reading them (NFD Cyrillic paths, font-encoded PDFs)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: e111f1b1-4f83-4542-aed1-80798dae1d9a
  modified: 2026-09-11T15:34:32.538Z
---

Source materials in /Users/dmitry/Projects/teta_new: `brief.md` (client brief), `prd.md` (ТЗ), `teta_info/` (DNK_TETA.pdf brand DNA, ТЕТА фирм стиль.pdf, logos, colors), `Информация/скриншоты платформы конкурента Ясно/` (45 Yasno client-flow screenshots; ASCII copies in docs/99_sources/yasno_screens/s01..s45.png). Test site https://test.teta.su, current https://teta.su.

Gotchas: the `Информация` folder name is NFD-normalized, so Read with a typed path fails — copy files via `find ... -print0` to ASCII names first. DNK PDF text is custom-font-encoded (garbage on extraction) — render pages with `uv run --with pymupdf` and read images. No poppler installed.

Brand (customer instruction 2026-09-11, overrides the style PDF): corporate colors graphite #4A4A4A, purple #4D427A, white — variants/tints allowed (scale in docs/brand/README.md); font Onest only. Logos: use only the customer files `teta_info/новое лого black.jpg` / `white.jpg` — the color in the name is the BACKGROUND (`black.jpg` = white mark on black, `white.jpg` = black mark on white). Transparent PNG copies: docs/brand/teta_logo_black.png (black mark) and teta_logo_white.png (white mark). Never redraw or typeset the logo. Related: [[teta-docs-phase-no-code]], [[teta-v2-decisions]].
