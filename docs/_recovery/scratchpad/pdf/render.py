import pymupdf, os
out="/private/tmp/claude-501/-Users-dmitry-Projects-teta-new/e111f1b1-4f83-4542-aed1-80798dae1d9a/scratchpad/pdf"
for src, tag in [("/Users/dmitry/Projects/teta_new/teta_info/DNK_TETA.pdf","dnk"),("/Users/dmitry/Projects/teta_new/teta_info/ТЕТА фирм стиль.pdf","style")]:
    d = pymupdf.open(src)
    for i, p in enumerate(d):
        print(tag, i+1, p.rect)
        p.get_pixmap(dpi=110).save(os.path.join(out, f"{tag}_hi_p{i+1:02d}.png"))
