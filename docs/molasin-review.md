# Molasin Tutoring — review tracker

Items from the client review (Oct 2026). ☐ open · ◐ in progress · ☑ done.

- ☑ **1** School onboarding: logo didn't show after upload (fixed by cross-origin + absolute-URL + initials fallback); brand colour reverts to default (fixed: `learnoInput` now binds `[value]` declaratively — hydration-safe — and the colour defaults to brand green `#39c645`).
- ☑ **2** After selecting a teacher/user for a role, the staff name wasn't shown. The assign dropdown does show the picked name; the real gap was the Roles table showing only a count. Now shows each role's assigned staff names (capped "+N more"), and the count/names are scoped to the school (system-role counts were previously global, leaking other schools' staff).
- ☐ **3** Rich-text editor for Lesson Objectives (bullets/numbering).
- ☐ **4** After clicking Lesson Content, Topics still shows.
- ☐ **5** Teacher's guide should be PDF, not downloadable; editor slow to paste; (Parent wording on hold).
- ☐ **6** Assessment / Question Bank / Portfolio create+upload in one window (not separate pages).
- ☐ **7** Bulk Question Bank upload unclear; template downloads as Excel but import asks for CSV.
- ☐ **8** Clarify the purpose of these features (needs discussion).
- ◐ **9** Student can't see Lesson note + YouTube link → content must be PUBLISHED (topic + delivery pack). Needs publish-state UX clarity. (Video cap raised; YouTube render verified.)
- ☐ **10** Portfolio task download formatting broken; prefer uploading a portfolio for now.
- ☐ **11** Resources tab: must download to view; opening navigates to "Assessment Gradebook".
- ☐ **12** Interventions: teachers shouldn't edit/delete admin-sent ones (resolve only); teacher-created ones notify admin; neither can delete.
- ☐ **13** Add class/subject label to Calendar activities.
- ☐ **14** Teacher can't select a learner to give feedback.
- ☑ **Video** Upload cap raised from 15 MB to 200 MB (lesson media); recommend YouTube/Vimeo links for long video (no size limit). Server limits (Nginx/PHP) must be raised to match.

NOTE (client): the boxed insight panels should be auto-generated from student activity; teacher inputs only override/add.
