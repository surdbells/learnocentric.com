import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {RichEditor} from '../../../../common/rich-editor/rich-editor';
import {ApiService} from '../../../../common/service/api.service';
import {AuthService} from '../../../../common/auth/auth.service';
import {FileUpload, UploadedFile} from '../../../../common/file-upload/file-upload';

const TEMPLATES: Record<string, {category: string; priority: string; body: string}> = {
  'Assessment Notice': {category: 'academics', priority: 'high', body: 'Dear learners,\n\nThis is to inform you that an assessment has been scheduled. Please revise all topics covered so far and arrive on time with the required materials.\n\nThank you.'},
  'Homework Reminder': {category: 'reminder', priority: 'medium', body: 'Dear learners,\n\nA gentle reminder that your homework is due soon. Please complete and submit it before the deadline.\n\nThank you.'},
  'Live Class Reminder': {category: 'reminder', priority: 'medium', body: 'Dear learners,\n\nYour live class is coming up. Please join on time and have your materials ready.\n\nSee you there!'},
  'Holiday Notice': {category: 'general', priority: 'low', body: 'Dear all,\n\nPlease note the upcoming holiday. The school will be closed on the dates below. Normal activities resume afterwards.\n\nThank you.'},
};

/** Create / edit a school announcement (design: Communication II_TD). */
@Component({
  selector: 'app-announcement-compose',
  standalone: true,
  imports: [PageHeader, Icon, RichEditor, FormsModule, FileUpload],
  templateUrl: './announcement-compose.html',
  styleUrl: './announcement-compose.css',
})
export class AnnouncementCompose {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private router = inject(Router);
  private toast = inject(ToastrService);

  readonly role = this.auth.getAuthSession()?.user?.role ?? '';
  readonly root = this.router.url.split('/')[1] || 'admin';
  readonly templateNames = Object.keys(TEMPLATES);

  readonly audiences = computed(() => this.role === 'teacher'
    ? [{v: 'class', l: 'Selected Class'}, {v: 'students', l: 'All Students'}, {v: 'parents', l: 'Parents'}]
    : [{v: 'all', l: 'Everyone'}, {v: 'students', l: 'All Students'}, {v: 'teachers', l: 'Teachers'}, {v: 'parents', l: 'Parents'}, {v: 'staff', l: 'Teachers & Staff'}, {v: 'class', l: 'Selected Class'}]);

  classes = signal<{id: number; label: string}[]>([]);
  subjects = signal<string[]>([]);
  busy = signal(false);
  editId = signal<number | null>(null);

  // form
  title = signal('');
  audience = signal(this.role === 'teacher' ? 'class' : 'all');
  classId = signal<number | null>(null);
  subject = signal('');
  priority = signal('medium');
  category = signal('general');
  body = signal('');
  channels = signal({in_app: true, email: false, parent_copy: false});
  scheduleOn = signal(false);
  scheduledDate = signal('');
  scheduledTime = signal('');
  attachmentUrl = signal('');
  attachmentName = signal('');

  constructor() {
    this.loadClasses();
    this.api.get<any>('/backend/school/subjects').subscribe({
      next: (r) => this.subjects.set((Array.isArray(r) ? r : r?.data ?? []).map((s: any) => s.name).filter(Boolean)),
      error: () => {},
    });
    // Prefill when editing a draft (passed via router state).
    const draft = (this.router.getCurrentNavigation()?.extras.state ?? history.state)?.['draft'];
    if (draft) this.prefill(draft);
  }

  private loadClasses(): void {
    const url = this.role === 'teacher' ? '/backend/teacher/classes' : '/backend/school/classes';
    this.api.get<any>(url).subscribe({
      next: (r) => {
        const rows = this.role === 'teacher' ? (r?.classes ?? []) : (Array.isArray(r) ? r : r?.data ?? []);
        this.classes.set(rows.map((c: any) => ({id: c.id, label: c.label})));
      },
      error: () => {},
    });
  }

  private prefill(d: any): void {
    this.editId.set(d.id ?? null);
    this.title.set(d.title ?? '');
    this.audience.set(d.audience ?? 'all');
    this.classId.set(d.class_id ?? null);
    this.subject.set(d.subject ?? '');
    this.priority.set(d.priority ?? 'medium');
    this.category.set(d.category ?? 'general');
    this.body.set(d.body ?? '');
    if (d.channels) this.channels.set({in_app: !!d.channels.in_app, email: !!d.channels.email, parent_copy: !!d.channels.parent_copy});
  }

  applyTemplate(name: string): void {
    const t = TEMPLATES[name];
    if (!t) return;
    this.category.set(t.category);
    this.priority.set(t.priority);
    if (!this.body().trim()) this.body.set(t.body);
    if (!this.title().trim()) this.title.set(name);
  }

  setChannel(key: 'in_app' | 'email' | 'parent_copy', v: boolean): void {
    this.channels.set({...this.channels(), [key]: v});
  }

  onAttachment(f: UploadedFile): void { this.attachmentUrl.set(f.url); this.attachmentName.set(f.name); }
  clearAttachment(): void { this.attachmentUrl.set(''); this.attachmentName.set(''); }

  readonly recipientEstimate = computed(() => {
    if (this.audience() === 'class') {
      return this.classId() ? 'Selected class' : 'Choose a class';
    }
    const map: Record<string, string> = {all: 'Everyone in the school', students: 'All students', teachers: 'All teachers', parents: 'All parents', staff: 'Teachers & staff'};
    return map[this.audience()] ?? '—';
  });

  private payload(intent: 'draft' | 'send'): any {
    const p: any = {
      title: this.title().trim(),
      body: this.body().trim(),
      audience: this.audience(),
      category: this.category(),
      priority: this.priority(),
      subject: this.subject().trim(),
      channels: this.channels(),
      attachment_url: this.attachmentUrl(),
      status: intent,
    };
    if (this.audience() === 'class') p.class_id = this.classId();
    if (this.scheduleOn() && this.scheduledDate()) {
      p.scheduled_at = `${this.scheduledDate()}T${this.scheduledTime() || '09:00'}:00`;
    }
    return p;
  }

  submit(intent: 'draft' | 'send'): void {
    if (!this.title().trim()) { this.toast.error('An announcement title is required'); return; }
    if (intent === 'send' && !this.body().trim()) { this.toast.error('Add a message before sending'); return; }
    if (this.audience() === 'class' && !this.classId()) { this.toast.error('Choose a class for this audience'); return; }
    this.busy.set(true);
    const id = this.editId();
    const req = id
      ? this.api.put(`/backend/messaging/announcements/${id}`, this.payload(intent))
      : this.api.post('/backend/messaging/announcements', this.payload(intent));
    req.subscribe({
      next: () => {
        this.toast.success(intent === 'draft' ? 'Draft saved' : 'Announcement sent');
        this.busy.set(false);
        this.router.navigate([`/${this.root}/communication/announcements`]);
      },
      error: (e: any) => { this.toast.error(e?.error?.error || 'Could not save'); this.busy.set(false); },
    });
  }

  cancel(): void { this.router.navigate([`/${this.root}/communication/announcements`]); }
}
