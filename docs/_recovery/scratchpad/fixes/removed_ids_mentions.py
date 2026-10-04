# -*- coding: utf-8 -*-
"""Remove references to deleted IDs (RISK-28, Q-34) that check_docs reports as undefined."""
D = "/Users/dmitry/Projects/teta_new/docs/01_inputs/"
fixes = {
    D + "decisions_v2.md": [
        ("RISK-28 и Q-34 удалены.", "Риск 28 и вопрос 34 из реестров v1 удалены."),
        ("| «сведения о здоровье», «специальная категория», `ПЭП`, `RISK-28` | DEC-24 |",
         "| «сведения о здоровье», «специальная категория», `ПЭП`, риск 28 из реестра v1 | DEC-24 |"),
        ("| DEC-35 | Q-03 (пожелания из Telegram) снят, Q-34 удалён полностью |",
         "| DEC-35 | Q-03 (пожелания из Telegram) снят, вопрос 34 (форма согласия на сведения о здоровье) удалён полностью |"),
        ("Сохраняются ID вопросов `Q-` и рисков `RISK-`.", "Сохраняются ID вопросов `Q-` и рисков `RISK-`; удалённые номера не переиспользуются."),
    ],
    D + "open_questions.md": [
        ("Q-34 удалён по указанию заказчика.", "вопрос 34 удалён по указанию заказчика."),
    ],
}
for path, pairs in fixes.items():
    s = open(path, encoding="utf-8").read()
    for a, b in pairs:
        n = s.count(a)
        if n != 1:
            print("SKIP (count=%d): %s" % (n, a[:70]))
            continue
        s = s.replace(a, b)
    open(path, "w", encoding="utf-8").write(s)
print("done")
