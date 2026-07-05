# Learnocentric Design System

A lightweight design system layered on **Bootstrap 5** + the *Dashbrd* theme. The goal:
consistent, modern, mobile-first UI that is applied **globally** rather than page-by-page.

Because the app is highly componentised (pages are composed from ~15 shared components),
the design system is delivered through **two mechanisms**:

1. **Global tokens & component styles** in [`src/styles.scss`](src/styles.scss) — every page
   inherits these automatically.
2. **Shared UI components** under `src/app/common/` and `src/app/components/` — upgrading one
   component updates every page that uses it.

---

## 1. Design tokens (`src/styles.scss`)

### Brand palette (green)
| Token | Value | Use |
|-------|-------|-----|
| `--brand-500` | `#39c645` | Primary brand — buttons, active states, fills |
| `--brand-600` | `#2ba53a` | Hover |
| `--brand-700` | `#1e8a2e` | Active, link/label text on light (AA contrast) |
| `--brand-300` | `#78d585` | Links/accents in dark mode |
| `--brand-50` | `#eefaf0` | Subtle backgrounds, avatar tints |
| `--brand-rgb` | `57,198,69` | For `rgba()` composition |

`--bs-primary` and the component-local button/link/nav/pagination/progress tokens are all
rewired to derive from `--brand-500`, so the whole Bootstrap surface follows the brand.

### Radius & elevation
- Radius scale: `--bs-border-radius` `0.65rem` (sm `0.45`, lg `0.9`, xl `1.15`).
- Shadows: `--shadow-xs|sm|md|lg` — soft, layered (used on cards, dropdowns, modals, drawer).

### Layout tokens
- `--sidebar-w: 264px`, `--sidebar-w-collapsed: 76px`, `--topbar-h: 64px`
- `--sidebar-bg`, `--sidebar-border` (theme-aware)

---

## 2. App shell

`src/app/pages/dashboard/dashboard.{ts,html,css}` composes the shell:

- **`<app-sidenav>`** (`common/layout/sidenav`): collapsible sidebar.
  - **Desktop expanded** (264px): accordion groups, only the active group auto-opens.
  - **Desktop rail** (76px): icons only; hovering a group shows a **flyout submenu**. Toggled
    from the topbar; state persisted in `localStorage.sidebarCollapsed`.
  - **Mobile** (< 992px): off-canvas **drawer** with backdrop; opened by the topbar hamburger,
    closed on nav, backdrop click, or Esc. Always shows full labels (rail styling is desktop-only).
- **`<app-topbar>`** (`common/layout/topbar`): hamburger (mobile) / collapse toggle (desktop),
  back button, page title, preference (theme + language) dropdown, and user menu with sign-out.

---

## 3. Shared component vocabulary

| Component | Selector | Purpose |
|-----------|----------|---------|
| Page header | `app-page-header` | Icon avatar + breadcrumb + title + action slot. Start every content page with it. |
| Stat card | `app-stat-card` | Dashboard metric (label / value / icon / link). |
| Dashboard card | `app-dashboard-card` | Titled content section with `[title-extension]` + `[body]` slots. |
| Data table | `app-data-table` | Native table: heads, dataFields, rows, checkbox/preview options. |
| Pagination | `app-data-table-numbering` | Pager for the data table. |
| Table search | `app-table-search` | Search + optional filter bar above a table. |
| Input | `[learnoInput]` | Labelled form input (CVA). |
| Select | `[learnoSelect]` | Labelled select, optional searchable/multi. |
| Button | `app-learno-button` | Primary action button (`text`, `btnColor`, `icon`, `isLoading`). |
| Skeleton | `app-skeleton-loader` | Loading placeholder (`variant`: text/card/avatar/table/input). |
| Loader | `app-loader` | Full-area spinner. |

### Global component styling (already applied)
- **Form labels**: sentence-case, medium weight (was uppercase).
- **Tables**: uppercase micro headers, comfortable row padding, brand hover; wrap in `.table-card`
  for a rounded, elevated surface.
- **Page header icon**: brand-tinted avatar.
- **Badges**: pill-shaped, semibold.
- **`.empty-state`**: centered icon + message helper for "no data" views.
- **Touch targets**: 44px min height on inputs/buttons below `lg`.

---

## 4. Page patterns

**List / table page**
```html
<app-page-header icon="local_library" action="Students">
  <app-learno-button text="Add Student" (clicked)="add()" />
</app-page-header>

<app-table-search title="student(s)" (search)="onSearch($event)" />
<app-skeleton-loader [isLoading]="isLoading()" variant="table" [rows]="5" [columns]="4">
  <app-data-table [tableHeads]="..." [dataFields]="..." [tableRows]="rows()" />
</app-skeleton-loader>
<app-data-table-numbering [tableData]="rows()" [currentPage]="page()" (pageChange)="..." />
```

**Dashboard** — 4-up stat grid (`col-12 col-md-6 col-xxl-3`) then an 8/4 content split
(`col-xxl-8` / `col-xxl-4`), each metric wrapped in a skeleton loader.

**Form** — 3-column grid (`col-lg-4`) in modals, full-width (`col-lg-12`) for textareas.

---

## 5. Rollout status

- [x] Global tokens + component-layer styling (all pages inherit)
- [x] Collapsible / responsive sidebar + topbar shell
- [x] Shared component upgrades — batch 1: `stat-card`, `dashboard-card`, `data-table`
      (rounded card surface + empty state), `table-search`, `learno-button` (real icons),
      `learno-modal` (lighter header, consistent close)
- [x] Shared component upgrades — batch 2: `loader` (brand spinner + theme-aware overlay);
      `learno-input` / `learno-select` / `skeleton-loader` / `learno-offset` inherit the global
      layer (sentence-case labels, green focus, themed skeletons) — no bespoke changes needed
- [x] Auth: **sign-in** page redesigned (two-panel brand + form, mobile-friendly, `(ngSubmit)` fix)
- [x] **Role dashboards (all 5)** — shared `user-intro` restyled as a brand-gradient welcome
      hero; replaced unloaded Tabler (`ti ti-*`) icons with Material Symbols; fixed `getTodayDate`
      NG0100 error and `isLoading`-never-resets-on-error (stat cards now render on API failure)
- [x] **File-manager** — sidebar rewired to real `routerLink`s + active states, restored search
      icon, dropped template-only placeholder items
- [x] File-manager child pages — `my-drive` / `assets` / `media` cleaned (broken Feather icons →
      Material Symbols, dead `.html` links neutralized)
- [x] **Chat** — broken icons fixed, dead profile link neutralized, dark-mode text-color bug fixed
- [x] **Calendar** — wrapped in a card; FullCalendar themed to the brand (green buttons, today
      highlight, event colors); `events-listing` track + icon fixed
- [x] **App-wide icon sweep** — all live Tabler (`ti ti-*`) and Feather (`data-feather`) icons
      replaced with Material Symbols; all dead `*.html` links neutralized (only commented-out
      references remain)
- [x] **Per-role QA pass with real backend** (via dev proxy) — verified super-admin, school-admin,
      teacher, student dashboards + admin students list with live data. Fixes from QA: null profile
      images now fall back to the avatar placeholder; bootstrap-datepicker today/active cell themed
      to the brand green.

### Note for QA with real data
With a **valid** token there is no 401 redirect loop, so data pages won't flood — populated
dashboards, tables, and charts can be verified normally. (`ApiService` itself has no retry; the
flood only happened with the invalid dev-seeded token.)

See [[ui-theming-architecture]] in agent memory for the "why" behind the CSS-variable approach.
