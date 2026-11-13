import {Component, effect, inject, input, signal} from '@angular/core';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';

interface Section { title: string; rows: {label: string; value: string}[]; }

const LABELS: Record<string, string> = {
  // learner
  class_level: 'Class level', arm: 'Arm', house: 'House', previous_school: 'Previous school',
  admission_date: 'Admission date', enrollment_status: 'Enrolment status',
  name: 'Name', relationship: 'Relationship', phone: 'Phone', email: 'Email', whatsapp: 'WhatsApp',
  address: 'Address', emergency: 'Emergency contact', has_account: 'Has a parent account',
  medical: 'Medical notes', special_needs: 'Special needs', transport: 'Transport', remarks: 'Remarks',
  parent: 'Parent consent', media: 'Media consent', data_privacy: 'Data privacy consent', comms_preference: 'Preferred contact',
  // staff
  staff_id: 'Staff ID', department: 'Department', employment_type: 'Employment type',
  qualification: 'Qualification', start_date: 'Start date',
  code_of_conduct: 'Code of conduct accepted', safeguarding_trained: 'Safeguarding trained',
};
const TITLES: Record<string, string> = {
  placement: 'Placement', guardian: 'Guardian (as recorded at admission)', support: 'Support & wellbeing',
  consent: 'Consents', employment: 'Employment', contact: 'Contact',
};

/**
 * Read-only view of what the school recorded when it admitted a learner or
 * onboarded a staff member (guardian contact, support notes, consents, staff
 * details). Admins only: the API returns it from /auth/user-profile/{id}.
 */
@Component({
  selector: 'app-onboarding-record',
  standalone: true,
  imports: [Icon],
  template: `
    @if (sections().length) {
      <div class="border rounded-3 p-3">
        <button type="button" class="btn btn-link p-0 text-decoration-none fw-semibold text-body d-flex align-items-center gap-1" (click)="open.set(!open())" [attr.aria-expanded]="open()">
          <app-icon name="description" [size]="18" class="text-success" /> {{ staff() ? 'Employment record' : 'Admission record' }}
          <app-icon name="expand_more" [size]="16" />
        </button>
        @if (open()) {
          <div class="row g-3 mt-1">
            @for (s of sections(); track s.title) {
              <div class="col-md-6">
                <div class="small text-uppercase text-body-secondary fw-semibold mb-1">{{ s.title }}</div>
                @for (r of s.rows; track r.label) {
                  <div class="d-flex justify-content-between gap-3 small py-1 border-top">
                    <span class="text-body-secondary">{{ r.label }}</span>
                    <span class="fw-medium text-end" style="overflow-wrap:anywhere">{{ r.value }}</span>
                  </div>
                }
              </div>
            }
          </div>
        }
      </div>
    }
  `,
})
export class OnboardingRecord {
  private readonly api = inject(ApiService);
  readonly userId = input<number | string | null | undefined>(null);

  open = signal(false);
  staff = signal(false);
  sections = signal<Section[]>([]);

  constructor() {
    effect(() => {
      const id = Number(this.userId() ?? 0);
      this.sections.set([]);
      this.open.set(false);
      if (id > 0) {
        this.api.get<any>(`/backend/auth/user-profile/${id}`).subscribe({
          next: (u) => this.build(u?.onboarding ?? null),
          error: () => this.sections.set([]),
        });
      }
    });
  }

  private build(record: Record<string, any> | null): void {
    if (!record) return;
    this.staff.set(!!record['employment']);
    const out: Section[] = [];
    for (const [key, group] of Object.entries(record)) {
      if (!group || typeof group !== 'object') continue;
      const rows = Object.entries(group as Record<string, any>)
        .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
        .map(([k, v]) => ({label: LABELS[k] ?? k.replace(/_/g, ' '), value: typeof v === 'boolean' ? (v ? 'Yes' : 'No') : String(v)}));
      if (rows.length) out.push({title: TITLES[key] ?? key, rows});
    }
    this.sections.set(out);
  }
}
