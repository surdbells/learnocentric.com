# LearnoCentric — UI Gap Register (design mockups vs current build)

Scope: 79 design screenshots across 4 roles. Decision: **keep the green theme** — gaps are structure/widgets/data/actions only, not colour. Caveat: written from static analysis; the frontend can't be built/previewed in the authoring env, so each item needs local build + visual QA.

Effort key: S (tweak/add a widget), M (substantial section or several widgets), L (near-rebuild or new page).

---

## ROLE 1 — LEARNER (18 screens)

### Whole missing pages (build from scratch)
- **Settings** — no learner settings route at all (settings hub: account, learning prefs, notifications, privacy/security, appearance, download/storage, language, help).
- **Ask Tutor** — only generic Messages exists; design is a tutoring page (available tutors, subject picker, online-now, live chat w/ rating, popular topics, past questions).
- **My Subjects** — referenced in nav/profile; no page (subjects list w/ progress, next topic, manage/enrol).
- **Portfolio Task Detail** — no per-task view (task brief, steps, resources, rubric, checklist, submission-format, per-task progress).
- **Resource / Video Detail player** — resources only open externally; no in-app viewer, chapters, transcript, notes, mark-complete.

### Highest-impact gaps on existing pages
1. **Dashboard** (L) — current = 4 stat cards + 3 charts + to-do + recent quizzes. Design = rich action hub: Continue Learning, Today's Lesson, Due Tasks (per-item due dates), Quiz Results + mastery bar, Weak Areas chips, Portfolio Task Status, Upcoming Live Class (Join), Feedback-from-Tutor quote, Common Mistake, My Progress (4 tiles incl. worksheet+portfolio completion), Recent Subjects cards, Next Recommended Action, class/grade chip.
2. **Quiz-taking engine** (L) — design = full timed exam (countdown, Pause, one-question paging, Prev/Next, Question Navigator grid, mark-for-review, attempts N of M, passing score, Clear Answer, Calculator, objectives, exam tip). Current = all questions on one scroll page, no timer.
3. **Worksheet doing view** (L) — design = sectioned (A/B/C/D) per-question answer fields, progress ring, Question Navigator, tools (calculator/scratchpad/formula/converter), zoom, autosave, Save&Exit vs Save&Continue, inline Ask-Tutor. Current = single rich-text box + file upload + Submit.
4. **Portfolio model mismatch** (L) — design = teacher-assigned tasks (briefs, rubrics, due dates, progress, thumbnails, star ratings). Current = free-form student evidence uploads. Different data model.
5. **List pages** (each L) — My Lessons, Quizzes&Assessments, Worksheets, Feedback, Resources, Live Classes, Progress all lack the design's KPI summary cards, tabs/filters, side-rail panels, and charts. Current = minimal card grids/lists.
6. **Feedback** (L) — no structured What-you-did-well / What-to-improve / Common-error / Next-step breakdown, no score, tutor identity, attached marked-work, summary ring, focus-area bars, or quick actions (redo/follow-up/ask).
7. **Progress** (L) — current progress-report is parent-oriented (export/teacher summary — keep). Design adds headline cards w/ trend, performance chart, subject-progress table, topic-mastery + skills bars, achievements, actionable Next Steps, Academic-vs-Skills split.
8. **Live Classes list** (M–L) — no Upcoming/Past tabs, next-class countdown hero, Join-with-code, Test-Connection, seats-left, calendar/today timeline, past recordings w/ Attended+duration, class rules.
9. **Live Class room** (L, mostly embed) — custom chrome (Lesson Outline w/ LIVE marker, Notes/Resources tabs, Class Resources, Objective/Vocabulary/Activity/Recording) not built; delegated to Daily embed.
10. **Resources list** (L) — no type KPI cards, subject filter, tabs/sort, Featured carousel, Recent table w/ size, Quick Access, Storage meter, per-subject counts, Request Resource, save/bookmark.
11. **Profile** (L) — current = basic personal-details form. Design adds Learning Snapshot, My Subjects, Achievements/badges, Goals, Learning Preferences, Account & Security (password/2FA/linked email), Learning Streak.

### Current-but-not-in-design (keep)
- Lesson viewer (Learn): tabbed Lesson/Media/Examples/**My Notes** + media playlist + journey checklist — richer than design.
- Progress Report: PDF/WhatsApp/copy export + structured teacher summary (parent-oriented).
- Report a Concern (safeguarding), Announcements route, module-gating (`moduleGuard`) — not in design set.

---

## ROLE 2 — TEACHER (~19 screens)

**Architecture note:** teacher has only 3 bespoke components (Dashboard, Learners, Settings/Profile); every "Academics" item **reuses the shared admin data-grid components**, so teacher screens inherit admin framing with none of the teacher-specific KPI/widget designs.

### Whole missing pages
- **My Classes** (L) — class roster/management page; only a dashboard stat count exists.
- **My Subjects & Curriculum** (L) — curriculum-coverage dashboard (week grid, objectives, pack readiness); scheme-of-work grid is the only nearby thing.
- **Assignments & Submissions** unified review inbox (L) — one queue across worksheets/portfolio/essays w/ scoring-method column, document viewer, editable rubric grid, misconception tagging, send-to-gradebook. Today: fragmented across worksheet/portfolio/assessment modals.
- **Learner Report Analysis** (L) — per-learner report/drill-in (trends, mastery, strengths/interventions, tutor plan, parent-ready PDF).
- **Resource Upload** (M) — teachers currently cannot upload resources at all (resources page is read-only consumer view).

### Existing pages needing rebuild to design depth
- **Dashboard** (L) — add pending-submissions review table, today's-schedule timeline, class-performance donut, quick-actions, unread-messages, deadlines, "how scores flow" explainer; 5 KPIs vs current 4.
- **Live Classes** (M) + in-session console (L, mostly embed) — KPI strip, today panel w/ countdown, attendance donut, recordings list.
- **Assessments & Question Bank** (M–L) — design unifies (app splits into 2 pages); add KPI cards, QB coverage donut, difficulty/misconception/Bloom's metadata, question preview panel, import/generate actions.
- **Gradebook** (L) — design is learner×component **matrix** w/ weights, approval status, letter grades, offline-score entry; current is one-metric-per-row analytics.
- **Learners** (L) — current is a 4-column contact table; design has performance-band/attendance/status roster + actions + side widgets.
- **Reports & Analytics** (L) — current reuses school-wide admin counts; design is teacher class-performance: trend/subject/mastery charts, quick reports, report cards, parent-share PDF/Excel.
- **Communication** (M) + Create Announcement (M) — audience tabs, templates, conversation-info panel; announcement priority/targeting/channels/schedule/preview/recipient-summary.
- **Settings** (L) — profile-only today; add notification/grading-review/security(2FA)/comms prefs + audit.
- **Lessons & Delivery Packs** (L) — materials-included checklist, readiness panel, completion donut, misconception-watch.

### Current-but-not-in-design (keep)
Lifecycle/moderation machinery (draft→…→archive + version history + answer-validation gate), Safeguarding & Interventions teacher pages, academic-vs-competency track separation in gradebook/analytics, module-gating, embedded live room w/ attendance.

## ROLE 3 — SCHOOL ADMIN (~24 screens)

**Systematic pattern (applies to almost every screen):** design = 5–6 KPI cards (w/ deltas/sparklines) + tabbed sub-views + right rail (Summary + "Attention Needed" + "Quick Actions") + rich tables + a global Session/Term switcher. Current = flat `app-data-grid` (table + filters + add/edit modal), thin columns, no metrics/tabs/rail.

### Whole missing pages (no route/menu item)
1. **School Setup** (L) — consolidated onboarding/config hub: academic structure, assessment weighting, report-card format, role-permission matrix, consent toggles, setup checklist.
2. **Lessons & Delivery Oversight** (L, 2 screens) — school-wide lesson-delivery monitoring, readiness/coverage KPIs, follow-up assignment.
3. **Reports & Report Cards** (L) — school performance report (term trend, class comparison, subject summary, report approval, share-with-leadership). Analytics page is lighter, not this.
4. **Calendar** (L) — month/week/agenda, academic calendar, deadlines, meeting scheduling.
5. **Curriculum Map** (L) — class×subject×term×week×topic grid w/ pack/assessment/portfolio status.
6. **Approval Queue** (M) — governance queue tab.
7. **Roles & Permissions** management (L) — only grading/safeguarding settings exist today.
8. **Scheme-of-Work / Delivery-Pack / Week-Resource detail pages** (L) — only modal edit exists.
9. **Assessment Review** (L) — per-assessment submission-review + moderation + score-distribution + return-to-teacher/approve-gradebook.

### Existing pages needing rebuild to design depth (all L unless noted)
- **Dashboard** — add attendance/performance-index KPIs w/ sparklines+deltas, curriculum-coverage donut, top subjects, per-class performance chart, teacher-activity panel, upcoming events.
- **Classes & Learners** — unify (currently split across students/enrollment/classes); add KPI strip, class cards w/ topic progress, rich learner table (admission no, gender, attendance, avg, intervention flag), Class Overview rail, bulk import, promote/reassign.
- **Add Learner / Add Staff** (M) — add sectioned form + enrollment/onboarding summary rail + completion checklist + Save Draft + guardian/consent/safeguarding/permissions sections.
- **Teachers & Staff** — KPI strip, tabs (support staff/assignments/permissions), rich directory (staff ID, subjects, classes, feedback %), Staff Overview rail, export/bulk import.
- **Subjects & Curriculum hub** (6 screens) — unify 4 flat grids into a tabbed hub; add KPIs, coverage/status columns, overview rails, weekly-flow previews, attention panels; **Curriculum Map + Approval Queue missing**; **scheme/pack/resource detail pages missing**. (Current HAS lifecycle+approval+version-history via modals — keep.)
- **Live Classes** (M) — KPI strip, tabs (upcoming/ongoing/completed/recordings), attendance+recording columns, summary/attention rail. (Current has real hosting — keep.)
- **Assessments & Gradebooks** — KPIs, unified hub w/ tabs, Assessment Review detail page, submissions-progress/gradebook-status columns, finalization workflow. (Current has builder + moderation + CSV — keep.)
- **Interventions** — KPIs, tabs, richer table (priority, next review, progress), summary/attention rails, analytics donuts, parent-meeting scheduling, export.
- **Resources** — MISMATCH: current is a consumer content-package viewer; design is admin resource **management** (library table, approval queue, upload, delivery-pack linkage, templates). Effectively a new page.
- **Communication** — unify messages+announcements into one hub; add KPIs, tabs (teacher/parent/logs), delivery-status/category/scheduled columns, blast/scheduled messaging, delivery reporting. (Current 1:1 threads — keep.)
- **Settings & Permissions** — KPIs, tabs (general/roles/policies/notifications/privacy/audit), localization/branding/data prefs, roles table + CRUD. Current = grading + safeguarding only.

### Current-but-not-in-design (keep)
Lifecycle/approval engines (topics/packs/assessments w/ version history + RBAC transitions), assessment builder, functional live-class room + attendance, 1:1 threaded messaging, subject-catalogue adopt/remove model, module-gating, gradebook/analytics CSV export, and Worksheets/Portfolio/Question-Bank/Billing pages (no SA design screen for these).

## ROLE 4 — SUPER ADMIN (~19 screens — thinnest current build)

Current super-admin menu exposes only: Dashboard + Management (Institutions, Subjects, Subscription Plans, Content Library, Content Packages, Support Centre). Design has 13 nav items → most are whole-page gaps.

### Whole missing pages (UI + often backend)
- **Users & Roles** (L) — no page; RBAC enforced server-side but **no user/role management API** (needs list users, role/permission templates, invites, bulk CSV, access logs).
- **Platform Analytics** (L) — needs **new cross-institution aggregation API** (DAU, regional, heatmap, churn); only school-scoped analytics exist.
- **Reports** engine (L) — needs report generation/scheduling/templates/approval backend; only raw CSV export exists.
- **Safeguarding & Compliance** (L) + Create Case form (L) — cases endpoint exists (school-scoped); needs platform scope + flagged-comms/consent/policy/escalation.
- **System Settings** (L) — needs platform config / feature-flags / integrations backend.
- **Audit Logs** (M) — **data already written** (`AuditLog`); just needs a **read/list endpoint** + UI. → good early win.
- **Curriculum & Content workspace** + Create Curriculum Pack wizard (L) — needs platform master-pack governance (current curriculum endpoints are school-scoped authoring).
- **Assessments & Question Bank** (platform) + Create Assessment wizard (L) — same school-scoped-vs-platform gap.
- **Resources Library** + Upload Resource form (L) — richer than the thin Content Library; needs approval workflow, usage tracking, folders.

### Thin partials needing rebuild
- **Dashboard** (L) — plain stats; needs usage chart, subscription-status, content-readiness, revenue (MRR/ARR), schools-requiring-attention table, support/safeguarding feeds, platform-health strip, date-range.
- **Institutions** (L) — basic 4-col table; needs KPIs, tabs, filters, rich columns (status/plan/usage/last-activity), lifecycle actions (suspend/verify/impersonate — **endpoints missing**, only GET/onboard exist).
- **Onboarding** (L) — flat form → 5-section wizard (academic setup, subscription/limits/modules, safeguarding/compliance).
- **Subscriptions & Billing** (L) — only Plan CRUD; needs billing table, renewals, invoices, payment-issues, MRR/ACV widgets (**invoice/renewal endpoints missing**).
- **Support Centre** (M) + ticket detail (M) — shared basic list; needs KPIs, SLA, category tabs, escalation path, rich detail.

### Backend status
- **UI-only gap (backend exists):** Dashboard, Institutions list/onboard, Subjects catalogue, Content library/packages, Plans + billing transactions, Support tickets, Safeguarding cases. **Audit Logs** = data written, needs read endpoint.
- **Needs new endpoints:** Users&Roles/RBAC mgmt, platform-wide analytics aggregation, reports engine, system settings, billing depth (invoices/renewals), institution lifecycle (suspend/verify), platform-scope curriculum/assessment governance, resource approval/usage/folders.

---

## CONSOLIDATED PLAN

### The one pattern behind ~80% of the gaps
Every design screen = **KPI-card strip** + **tabbed sub-views** + **right rail** (Summary + "Attention Needed" + "Quick Actions") + **rich data tables** + **charts/donuts**, with a global **Session/Term switcher** in the topbar. The current app has almost none of these. So the highest-leverage move is to build these as **shared, reusable components once**, then apply them everywhere.

### Scale
~28 whole-new pages + ~45 existing-page rebuilds across 4 roles; several are **backend-affecting** (task-driven portfolio, timed-quiz engine, gradebook matrix+weights, and the whole Super-Admin new-endpoint set). This is a multi-week program; nothing here is verifiable in the authoring env (needs local build + visual QA per screen).

### Recommended phasing
- **Phase 0 — Shared UI foundation (do first, unblocks everything):** `kpi-strip`, `page-rail` (summary/attention/quick-actions), `tab-bar`, `stat-donut`/`trend-chart` wrappers, richer `data-grid` columns (bars, badges, avatars, row-actions), topbar Session/Term switcher. Small count, huge leverage.
- **Phase 1 — 4 Dashboards** — highest visibility; exercises the Phase-0 primitives.
- **Phase 2 — High-traffic existing pages to design depth** (per role): Learner lessons/quizzes/worksheets/feedback; Teacher submissions-inbox/gradebook/learners; School-Admin classes&learners + subjects&curriculum hub; Super-Admin institutions.
- **Phase 3 — New pages that already have backend:** Audit Logs (read endpoint + UI), Users&Roles (once endpoint), Calendar, School Setup, detail pages.
- **Phase 4 — Backend data-model work:** task-driven portfolio, timed-quiz engine, gradebook weights.
- **Phase 5 — Super-Admin new-endpoint pages:** platform analytics, reports engine, system settings, billing depth, safeguarding platform surface.

