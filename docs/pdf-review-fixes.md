# PDF Review — Fix Tracker

Captured from *Student & Teacher Review* and *SCHOOL_SUPER ADMIN* PDFs (annotated screenshots). Status: ☐ todo · ◐ in progress · ☑ done.

## Learner (student portal)
- ☑ **S1** Learn page → remove the stray icon toolbar in the "Lesson stats" card.
- ☑ **S2** Subject Hub hero → reposition the "Continue" CTA (currently crammed inline — "not rightly placed").
- ☑ **S3** My Assessments → the "Assessment" tab (boxed) — review/clean (empty tab when 0 assessments).
- ☑ **S4** My Assessments (Quiz view) → "Topic performance" bar styling looks off.
- ☑ **S5** Merge "Resources" + "Resource Viewer" nav into one.
- ◐ **S6** Progress Report → add a **skill progress snapshot/summary drawn from portfolio** (= backend done; frontend pending).
- ☑ **S7** Remove the "Communication" section from the learner nav (Notifications + Ask a Tutor is enough).
- ☑ **S8** Learner Settings → removed "Download & Storage" and "Language" sections (confirmed with user).

## Teacher portal
- ☑ **T1** Merge "Topics" + "Lesson Content" nav (topic info captured on Lesson Content).
- ☑ **T2** Remove "Curriculum Map" from teacher nav (School Admin's).
- ☑ **T3** Remove "Approval Queue" from teacher nav (School Admin / role-gated).
- ☑ **T4** Question Bank → add a **Subject filter**.
- ☑ **T5** Add Question form → add a **Subject** selector (multi-subject teachers).
- ☑ **T6/T7** Assessment builder → **auto-draw questions from the bank** by topic; stop manual add-one-by-one (all 3 modes: draw-all / draw-N-random / topic-filtered picker).
- ☑ **T8** Add Worksheet form → add a **Subject** selector.
- ☑ **T9** Remove "Interventions" from teacher nav (School Admin's).
- ☑ **T10** Merge "Resources" + "Resource Viewer" (teacher nav).
- ☑ **T11** Teacher Settings "Class & Communication Preferences" → reviewed; left as-is (all four fields functional; user had no preference on changes, no annotation available).
- ☑ **T12** Give Feedback → teacher must be able to **see the learner's work** being graded.
- ☑ **T13** Add the "Assignments & Submissions" unified grading inbox (missing; needed for manual grading).

## School Admin portal
- ☑ **A1** Merge "Students" + "Enrollment"; enroll (assign class) **while adding a learner**; remove the separate "New Student" tab.
- ☑ **A2** Remove the "New Teacher" tab — add staff from the Teachers page.
- ☑ **A3** Merge the "Class" page into "Classes & Learners" (bring over the **delete** option).
- ☐ **A4** (design) Enrollment as a **tab** within Classes & Learners; reduce left-nav clutter.
- ☑ **A5** Subjects page → remove the "Catalogue subjects" stat (Super-Admin concern).
- ☑ **A6** Approval Queue → add a **"Review"** (view item) action before Approve/Return.
- ☑ **A7** "School Setup" → removed from school-admin & academy nav (and the Settings rail quick-action); /admin/setup route kept for deep-links.
- ☑ **A8** Calendar → proper **month/week calendar** (not a list) per the PNG.
- ☑ **A9** Settings & Permissions → staff can now be assigned to school-scoped system staff roles (e.g. Academic Lead), not just custom roles; platform/learner/guardian roles stay unassignable (server-enforced).
- ◐ **A10** (general) match the PNG feature flow — minor missing details. Blocked: needs the annotated PNGs (not committed to repo) to identify the specific gaps.

## Original two (from the message)
- ◐ #1 = **S6** (skill progress from portfolio).
- ◐ #2 = **T6/T7** (draw questions from bank).
