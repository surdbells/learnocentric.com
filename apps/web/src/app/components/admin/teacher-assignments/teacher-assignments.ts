import {Component, effect, inject, input, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';

interface Assignment { id: number; class_id: number; class_label: string; subject_id: number; subject: string; }

/**
 * What a teacher teaches: subject-to-class assignments (admin). These decide
 * the teacher's "My classes", learner roster, tutor inbox and submissions.
 * Safe to place inside another form: it never submits the host form.
 */
@Component({
  selector: 'app-teacher-assignments',
  standalone: true,
  imports: [FormsModule, Icon],
  template: `
    <div class="border rounded-3 p-3" (keydown.enter)="$event.preventDefault()">
      <div class="fw-semibold mb-1"><app-icon name="school" [size]="18" class="text-success" /> Teaching assignments</div>
      <div class="small text-body-secondary mb-2">The classes and subjects this person teaches. They decide what appears in their classes, roster and submissions.</div>

      @for (a of rows(); track a.id) {
        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-top">
          <div><span class="fw-medium">{{ a.subject }}</span> <span class="text-body-secondary">· {{ a.class_label }}</span></div>
          <button type="button" class="btn btn-sm btn-outline-danger" (click)="remove(a)" [attr.aria-label]="'Remove ' + a.subject + ' for ' + a.class_label"><app-icon name="delete" [size]="15" /></button>
        </div>
      } @empty {
        <div class="small text-body-secondary py-1">Nothing assigned yet.</div>
      }

      <div class="row g-2 align-items-end border-top pt-3 mt-2">
        <div class="col-md-5">
          <label class="form-label small mb-1" for="ta_class">Class</label>
          <select id="ta_class" class="form-select form-select-sm" [ngModel]="classId()" (ngModelChange)="classId.set($event)" [ngModelOptions]="{standalone: true}">
            <option value="">Choose…</option>
            @for (c of classes(); track c.id) { <option [value]="'' + c.id">{{ c.label }}</option> }
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small mb-1" for="ta_subject">Subject</label>
          <select id="ta_subject" class="form-select form-select-sm" [ngModel]="subjectId()" (ngModelChange)="subjectId.set($event)" [ngModelOptions]="{standalone: true}">
            <option value="">Choose…</option>
            @for (s of subjects(); track s.id) { <option [value]="'' + s.id">{{ s.name }}</option> }
          </select>
        </div>
        <div class="col-md-2 d-grid">
          <button type="button" class="btn btn-sm btn-success" [disabled]="busy() || !classId() || !subjectId()" (click)="add()">Assign</button>
        </div>
      </div>
    </div>
  `,
})
export class TeacherAssignments {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly teacherId = input<number | string | null | undefined>(null);

  rows = signal<Assignment[]>([]);
  classes = signal<{id: number; label: string}[]>([]);
  subjects = signal<{id: number; name: string}[]>([]);
  classId = signal('');
  subjectId = signal('');
  busy = signal(false);
  private optionsLoaded = false;

  constructor() {
    effect(() => {
      const id = Number(this.teacherId() ?? 0);
      this.rows.set([]);
      if (id > 0) {
        this.load(id);
        this.loadOptions();
      }
    });
  }

  private load(id: number): void {
    this.api.get<any>(`/backend/school/teacher-assignments?teacher_id=${id}`).subscribe({
      next: (r) => this.rows.set(r?.data ?? []),
    });
  }

  private loadOptions(): void {
    if (this.optionsLoaded) return;
    this.optionsLoaded = true;
    const list = (r: any) => (Array.isArray(r) ? r : (r?.data ?? []));
    this.api.get<any>('/backend/school/classes').subscribe({
      next: (r) => this.classes.set(list(r).map((c: any) => ({id: c.id, label: c.label || c.name})).filter((c: any) => c.id && c.label)),
    });
    this.api.get<any>('/backend/school/subjects').subscribe({
      next: (r) => this.subjects.set(list(r).map((s: any) => ({id: s.id, name: s.name})).filter((s: any) => s.id && s.name)),
    });
  }

  add(): void {
    const teacher_id = Number(this.teacherId());
    this.busy.set(true);
    this.api.post<any>('/backend/school/teacher-assignments', {teacher_id, class_id: Number(this.classId()), subject_id: Number(this.subjectId())}).subscribe({
      next: (a) => { this.busy.set(false); this.subjectId.set(''); this.toast.success(`${a.subject} assigned for ${a.class_label}`); this.load(teacher_id); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not assign'); },
    });
  }

  remove(a: Assignment): void {
    this.api.delete<any>(`/backend/school/teacher-assignments?id=${a.id}`, {confirm: `Stop ${a.subject} for ${a.class_label}? The class will leave this teacher's list.`}).subscribe({
      next: () => { this.toast.success('Assignment removed'); this.load(Number(this.teacherId())); },
      error: (e) => this.toast.error(e?.error?.error || 'Could not remove'),
    });
  }
}
