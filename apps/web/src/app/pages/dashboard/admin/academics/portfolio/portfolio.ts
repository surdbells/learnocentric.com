import {Component, computed, inject, input, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {RichText} from '../../../../../common/rich-editor/rich-text';

declare const bootstrap: any;

const RATING_COLOR: Record<string, string> = {emerging: 'secondary', developing: 'info', proficient: 'primary', mastery: 'success'};
const RATINGS = ['emerging', 'developing', 'proficient', 'mastery'];

@Component({
  selector: 'app-portfolio',
  standalone: true,
  imports: [RichText, RichEditor, PageHeader, LearnoModal, DataGrid, FormsModule, DatePipe, Icon],
  templateUrl: './portfolio.html',
  styleUrl: './portfolio.css',
})
export class Portfolio {
  /** Hidden page header when hosted inside a hub. */
  readonly embedded = input<boolean>(false);

  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  review = signal<any | null>(null);
  rating = signal<string>('');
  feedback = signal<string>('');
  busy = signal(false);
  subjects = signal<any[]>([]);

  // New portfolio task (assign an evidence brief to a topic)
  topics = signal<any[]>([]);
  taskTopicId = signal<number | null>(null);
  taskBrief = signal<string>('');
  taskCompetency = signal<string>('');
  taskBusy = signal(false);
  readonly selectedTopic = computed(() => this.topics().find((t) => t.id === this.taskTopicId()) ?? null);

  // Structured portfolio-task template content (populates the generated task sheet).
  taskAim = signal<string>('');
  taskSubAim = signal<string>('');
  taskMission = signal<{ label: string; detail: string }[]>([]);
  taskEvidence = signal<{ caption: string }[]>([]);
  taskRubric = signal<{ criterion: string; standard: string }[]>([]);

  readonly ratings = RATINGS;

  columns: GridColumn[] = [
    {key: 'student', label: 'Student', sortable: false},
    {key: 'title', label: 'Evidence', sortable: true},
    {key: 'topic', label: 'Topic'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: v === 'reviewed' ? 'success' : 'info'})},
    {key: 'competency_rating', label: 'Competency', type: 'badge', badge: (v) => v ? {text: this.titleCase(v), color: RATING_COLOR[v] ?? 'secondary'} : {text: '-', color: 'light'}},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'status', label: 'Status', options: [{label: 'Submitted', value: 'submitted'}, {label: 'Reviewed', value: 'reviewed'}]},
    {key: 'competency_rating', label: 'Competency', options: RATINGS.map((r) => ({label: this.titleCase(r), value: r}))},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.loadTopics();
  }

  private loadTopics(): void {
    this.api.get<any>('/backend/curriculum/topics').subscribe({
      next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? [])),
    });
  }

  private defaultMission(): { label: string; detail: string }[] {
    return [
      {label: '1 MODEL', detail: ''}, {label: '2 SOLVE', detail: ''},
      {label: '3 DESIGN', detail: ''}, {label: '4 EXPLAIN', detail: ''},
    ];
  }
  private defaultEvidence(): { caption: string }[] {
    return [{caption: ''}, {caption: ''}, {caption: ''}];
  }
  private defaultRubric(): { criterion: string; standard: string }[] {
    return [
      {criterion: 'Knowledge', standard: ''}, {criterion: 'Skill', standard: ''},
      {criterion: 'Real-life application', standard: ''}, {criterion: 'Communication', standard: ''},
      {criterion: 'Collaboration', standard: ''},
    ];
  }

  /** Load structured template content from a topic, falling back to canonical defaults. */
  private loadTaskTemplate(t: any | null): void {
    const pt = t?.portfolio_task ?? null;
    this.taskAim.set(pt?.aim ?? '');
    this.taskSubAim.set(pt?.sub_aim ?? '');
    this.taskMission.set(pt?.mission?.length ? pt.mission.map((m: any) => ({label: m.label ?? '', detail: m.detail ?? ''})) : this.defaultMission());
    this.taskEvidence.set(pt?.evidence?.length ? pt.evidence.map((e: any) => ({caption: typeof e === 'string' ? e : (e?.caption ?? '')})) : this.defaultEvidence());
    this.taskRubric.set(pt?.rubric?.length ? pt.rubric.map((r: any) => ({criterion: r.criterion ?? '', standard: r.standard ?? ''})) : this.defaultRubric());
  }

  addMission(): void { this.taskMission.update(a => [...a, {label: '', detail: ''}]); }
  removeMission(i: number): void { this.taskMission.update(a => a.filter((_, idx) => idx !== i)); }
  addEvidence(): void { this.taskEvidence.update(a => [...a, {caption: ''}]); }
  removeEvidence(i: number): void { this.taskEvidence.update(a => a.filter((_, idx) => idx !== i)); }
  addRubric(): void { this.taskRubric.update(a => [...a, {criterion: '', standard: ''}]); }
  removeRubric(i: number): void { this.taskRubric.update(a => a.filter((_, idx) => idx !== i)); }

  /** Open the "New portfolio task" composer. */
  onNewTask(): void {
    this.taskTopicId.set(null);
    this.taskBrief.set('');
    this.taskCompetency.set('');
    this.loadTaskTemplate(null);
    const el = document.getElementById('portfolio_task');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  /** Pre-fill the brief when a topic that already has one is chosen (so it can be edited). */
  onPickTopic(id: number | null): void {
    this.taskTopicId.set(id);
    const t = this.selectedTopic();
    this.taskBrief.set(t?.portfolio_evidence_expected ?? '');
    this.taskCompetency.set(t?.competency_built ?? '');
    this.loadTaskTemplate(t);
  }

  submitTask(): void {
    const id = this.taskTopicId();
    if (!id) { this.toast.error('Choose a topic'); return; }
    if (!this.taskBrief().trim()) { this.toast.error('Describe the evidence the learner should submit'); return; }
    this.taskBusy.set(true);
    this.api.put<any>('/backend/assessment/portfolio/tasks', {
      topic_id: id,
      portfolio_evidence_expected: this.taskBrief().trim(),
      competency_built: this.taskCompetency().trim(),
      portfolio_task: {
        aim: this.taskAim().trim(),
        sub_aim: this.taskSubAim().trim(),
        mission: this.taskMission().filter(m => m.label.trim() || m.detail.trim()),
        evidence: this.taskEvidence().filter(e => e.caption.trim()),
        rubric: this.taskRubric().filter(r => r.criterion.trim() || r.standard.trim()),
      },
    }).subscribe({
      next: (res) => {
        this.toast.success(res?.published
          ? 'Portfolio task assigned, learners can see it now.'
          : 'Portfolio task saved. It reaches learners once the topic is published.');
        this.taskBusy.set(false);
        this.loadTopics();
        const el = document.getElementById('portfolio_task');
        if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
      },
      error: (err) => { this.toast.error(err?.error?.error || 'Could not assign the task'); this.taskBusy.set(false); },
    });
  }

  onView(row: any): void {
    this.review.set(row);
    this.rating.set(row.competency_rating ?? '');
    this.feedback.set(row.reviewer_feedback ?? '');
    const el = document.getElementById('portfolio_review');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  submitReview(): void {
    const e = this.review();
    if (!e) return;
    if (!this.rating()) { this.toast.error('Choose a competency rating'); return; }
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/portfolio/${e.id}/review`, {competency_rating: this.rating(), feedback: this.feedback()}).subscribe({
      next: (res) => {
        this.toast.success('Evidence reviewed');
        this.review.set(res);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (err) => { this.toast.error(err?.error?.error || 'Review failed'); this.busy.set(false); },
    });
  }

  ratingColor(r: string): string { return RATING_COLOR[r] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
