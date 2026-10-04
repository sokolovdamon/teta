import fitz, sys, os
out = sys.argv[1]
for src, tag in [("/Users/dmitry/Projects/teta_new/teta_info/DNK_TETA.pdf","dnk"),("/Users/dmitry/Projects/teta_new/teta_info/ТЕТА фирм стиль.pdf","style")]:
    d = fitz.open(src)
    print(f"=== {tag}: {d.page_count} pages ===")
    for i, p in enumerate(d):
        t = p.get_text().strip()
        print(f"--- {tag} p{i+1} ---\n{t}\n")
        pix = p.get_pixmap(dpi=70)
        pix.save(os.path.join(out, f"{tag}_p{i+1:02d}.png"))
    # fonts
    fonts=set()
    for p in d:
        for f in p.get_fonts(): fonts.add(f[3])
    print(f"FONTS {tag}:", fonts)
