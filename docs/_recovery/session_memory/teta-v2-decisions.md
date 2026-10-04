---
name: teta-v2-decisions
description: "Customer decisions for TETA (2026-09-11) that override earlier analyst recommendations — stack, money model, removed tools, privacy, fonts"
metadata: 
  node_type: memory
  type: project
  originSessionId: e111f1b1-4f83-4542-aed1-80798dae1d9a
  modified: 2026-09-11T15:34:34.489Z
---

Decisions given by the user on 2026-09-11 (full register: docs/01_inputs/decisions_v2.md):
- Stack from TZ v2: Laravel 13, Next.js 16 PWA, PostgreSQL 18, Redis; video = TetaMeet (on Executor's DevMeet), no call recording, no audio-only mode; own email system via customer SMTP relay; payment provider chosen by customer; Yandex Metrika; Dzen autopost.
- Removed: Jivo (own support widget instead), DashaMail, iCal/calendar export, Yandex ID/VK ID, Apple Health/Health Connect, 2FA, partner (white-label) cabinet — white-label is managed in the admin panel; font Oswald — only Onest everywhere. Brand colors #4A4A4A/#4D427A/white + variants; logos only from customer files (never redrawn).
- Money: platform is ИП на УСН, an aggregator; psychologists are НПД; commission 30%; weekly payouts; no FNS НПД status checks, no income-limit tracking. Payout allowed only if one supervision per month is completed and paid (from balance or linked card); otherwise psychologist page is hidden. 54-ФЗ: B2C PREPAYMENT_FULL, B2B CREDIT_PAYMENT.
- Late cancellation: full retention; complaint refunds reviewed within 14 business days. Each psychologist sets own price; 50/90-minute sessions.
- Privacy: data is «сведения о состоянии», not health special category; no health-data consent; HR receives employee email and session count.
- УТП: «Только дипломированные специалисты». Requests (topics) = Yasno taxonomy + «Не могу найти партнёра», each with a landing page.
- Answers of 2026-09-11 (DEC-36…58): hosting VDS Timeweb; payment provider chosen only after all other modules (build money on an adapter + emulator); own mail server set up after launch; white-label = separate instance on a separate server per partner (no multi-tenancy, module INSTANCE); client balance — refunds when changing psychologist go to cabinet balance; reschedule after charge allowed if new session ≥12 h away; price categories до 3500 / 3500–5500 / от 5500 ₽; promo discount reduces only platform share; referral, gift certificates, psychologist video cards — yes; no psychologist reply to reviews; no data migration, don't touch Tilda/OnDoc; articles: moderation → site + auto Dzen; clients 18+; ИП Иващенко requisites; Герман on Russian/local LLM, admin on duty 10–20 MSK only for hard cases (no-show, refunds); 289-ФЗ n/a; Plan-grafik approved.

**Why:** these override v1 analyst recommendations (2FA, LiveKit, k-anonymity for HR, ПЭП consent, crisis screening, MVP scope).

**How to apply:** never reintroduce removed items; when a doc conflicts, these decisions win. Related: [[teta-full-product-no-mvp]], [[teta-docs-phase-no-code]].
