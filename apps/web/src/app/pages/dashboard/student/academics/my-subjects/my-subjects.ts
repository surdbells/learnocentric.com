import {Component, computed, inject, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {KpiStrip, KpiItem} from '../../../../../common/ui';

/**
 * Learner "My Subjects" — the subjects the student is enrolled in, each with
 * aggregate progress, topic counts, the next topic to continue, and the
 * assigned teacher. Backed by /learn/subjects (real topic-journey progress).
 */
@Component({
  selector: 'app-my-subjects',
  standalone: true,
  imports: [PageHeader, Icon, RouterLink, KpiStrip],
  templateUrl: './my-subjects.html',
  styleUrl: './my-subjects.css',
})
export class MySubjects {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  subjects = signal<any[]>([]);

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/learn/subjects').subscribe({
      next: (res) => { this.subjects.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your subjects'); },
    });
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.subjects();
    const topics = s.reduce((a, x) => a + (x.topic_count || 0), 0);
    const completed = s.reduce((a, x) => a + (x.completed_topics || 0), 0);
    const avg = s.length ? Math.round(s.reduce((a, x) => a + (x.progress || 0), 0) / s.length) : 0;
    return [
      {label: 'My Subjects', value: s.length, icon: 'menu_book', tone: 'primary'},
      {label: 'Avg Progress', value: avg + '%', icon: 'trending_up', tone: 'success'},
      {label: 'Topics Completed', value: completed + ' / ' + topics, icon: 'task_alt', tone: 'info'},
    ];
  });

  progressTone(p: number): string {
    if (p >= 75) return 'success';
    if (p >= 40) return 'warning';
    return 'danger';
  }
}
