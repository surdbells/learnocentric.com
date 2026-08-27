import {inject, Injectable} from '@angular/core';
import {Title} from '@angular/platform-browser';
import {RouterStateSnapshot, TitleStrategy} from '@angular/router';

const BRAND = "Learn-O'Centric";

/** URL segment -> nicer label where title-casing the raw segment isn't enough. */
const LABELS: Record<string, string> = {
  main: 'Dashboard',
  'my-subjects': 'My Subjects',
  'assessments-worksheets': 'Coursework',
  'progress-feedback': 'Progress',
  'report-concern': 'Report a Concern',
  'ask-tutor': 'Ask Tutor',
  'live-classes': 'Live Classes',
  'classes-learners': 'Classes & Learners',
  'scheme-of-work': 'Scheme of Work',
  'scheme-coverage': 'Scheme Coverage',
  'lesson-content': 'Lesson Content',
  'delivery-pack': 'Delivery Pack',
  'curriculum-map': 'Curriculum Map',
  'approval-queue': 'Approval Queue',
  'question-bank': 'Question Bank',
  'resource-viewer': 'Resource Viewer',
  'report-cards': 'Report Cards',
  'school-report': 'School Report',
  'users-roles': 'Users & Roles',
  'audit-logs': 'Audit Logs',
  'system-settings': 'System Settings',
  'catalog-subjects': 'Catalog Subjects',
  'content-library': 'Content Library',
  'content-packages': 'Content Packages',
  'subscription-plans': 'Subscription Plans',
  plans: 'Subscription Plans',
  'new-student': 'Add Learner',
  'new-teacher': 'Add Staff',
  'school-profile': 'School Profile',
  'academy-profile': 'Academy Profile',
  'report-concern-page': 'Report a Concern',
};

/**
 * Sets the browser-tab title for every route. Routes that declare an explicit
 * `title` (the public marketing pages) keep it as-is; dashboard routes have none,
 * so we derive a clean title from the URL's last meaningful segment and append
 * the brand — giving e.g. "Gradebook | Learn-O'Centric" without touching 100+ routes.
 */
@Injectable()
export class AppTitleStrategy extends TitleStrategy {
  private readonly title = inject(Title);

  override updateTitle(state: RouterStateSnapshot): void {
    const explicit = this.buildTitle(state);
    if (explicit) {
      this.title.setTitle(explicit);
      return;
    }

    const path = state.url.split('?')[0].split('#')[0];
    const segs = path.split('/').filter((s) => s && !/^\d+$/.test(s)); // drop numeric ids
    const last = segs[segs.length - 1] ?? '';
    const name = LABELS[last] ?? this.humanize(last);
    this.title.setTitle(name ? `${name} | ${BRAND}` : `${BRAND} | School Management System`);
  }

  private humanize(seg: string): string {
    if (!seg) return '';
    const small = new Set(['of', 'and', 'a', 'the', 'to', 'in', 'for']);
    return seg.split('-')
      .map((w, i) => (i > 0 && small.has(w)) ? w : w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ');
  }
}
