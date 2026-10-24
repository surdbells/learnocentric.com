# Molasin Tutoring — second review tracker

Items from the client's SECOND review (Oct 2026). ☐ open · ◐ in progress · ☑ done.

- ☑ **R2-1** Class teacher assignment added. The class form now has a Class teacher picker (loads teachers); backend accepts `classTeacherId` on create/update. Fixed ClassesLearners to show the explicit class teacher (it read a TeacherAssignment before, so an assigned class teacher never appeared). Verified: JSS 1 A → Grace Uche shows on the card + overview.
- ☑ **R2-2** Class naming hints. `learnoInput` now supports a placeholder; the class form uses clear labels + examples ("Level e.g. JSS 1", "Arm e.g. A"), a live name preview, and — key fix — Level is now a text field (was number-only, so "JSS 1" couldn't be typed). Dropped the ignored "Class name"/"Academic Year" fields that caused the "12 Mathematics" confusion.
- ☑ **R2-3** Approved/published content is now locked in the UI. Added per-row action gating to the data grid: Edit is hidden on packs/topics that aren't draft/review (for everyone — the backend already 409s those edits), and teachers can't Delete approved/published ones (only admins can). So a teacher no longer gets the edit form (or the teacher-guide upload prompt) on an admin-approved pack. Backend was already secure (LifecycleService restricts approve/return/publish to admins). Verified: as a teacher, a Published pack shows View only.
- ☑ **R2-4** Assign content to a class. The topic form now has a Class selector ("All classes" or a specific class); the backend accepts/clears `class_id`. The Topics grid shows a Class column so you can see what's targeted where. Learner visibility already honours this (a learner only sees topics for their class or "all classes"). Verified: a topic saved as JSS 1 A round-trips with class_label.
- ☐ **R2-5** "Published but student can't see it" — approve≠publish, topic+pack both need publishing, and the assigned class had 0 learners enrolled. Extend the visibility guidance.
- ☐ **R2-6** Bulk QBank upload rejects the real/agreed sheet ("must include stem") — accept the agreed column names.
- ☐ **R2-7** Template missing misconception data — add Misconception Tag + Common Wrong Answer (trimmed-but-agreed set) and capture them.

Decisions: QBank template = **trimmed set** (only fields the system uses) but accept the agreed column names as aliases. Work top-to-bottom, verify against the running app, commit per item, push to deploy.
