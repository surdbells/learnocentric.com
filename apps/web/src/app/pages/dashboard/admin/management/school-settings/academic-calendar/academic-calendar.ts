import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../../../../common/service/api.service';
import {Icon} from '../../../../../../common/icon/icon';
import {TermContext} from '../../../../../../common/service/term-context';

interface CalTerm { id: number; name: string; sequence: number; start_date: string | null; end_date: string | null; is_current: boolean; }
interface CalSession { id: number; name: string; is_current: boolean; archived?: boolean; terms: CalTerm[]; }

/**
 * Settings → Academic calendar. The school's sessions and terms: set real term
 * dates, choose the current term (what the top-bar term switcher defaults to),
 * and start the next academic year. Backed by /school/academic-calendar.
 */
@Component({
  selector: 'app-academic-calendar',
  standalone: true,
  imports: [FormsModule, Icon],
  templateUrl: './academic-calendar.html',
})
export class AcademicCalendar {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly termContext = inject(TermContext);

  loading = signal(true);
  busy = signal<number | null>(null);
  sessions = signal<CalSession[]>([]);
  nextName = signal('');
  starting = signal(false);

  constructor() { this.load(); }

  load(): void {
    this.api.get<{sessions: CalSession[]; suggested_next: string}>('/backend/school/academic-calendar').subscribe({
      next: (r) => { this.sessions.set(r?.sessions ?? []); this.nextName.set(r?.suggested_next ?? ''); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the academic calendar'); },
    });
  }

  /** Edit a field locally; saved with the row's Save button. */
  patch(term: CalTerm, change: Partial<CalTerm>): void {
    this.sessions.set(this.sessions().map(s => ({...s, terms: s.terms.map(t => t.id === term.id ? {...t, ...change} : t)})));
  }

  save(term: CalTerm): void {
    if (!term.name.trim()) { this.toast.error('A term needs a name'); return; }
    this.busy.set(term.id);
    this.api.put<CalTerm>(`/backend/school/academic-calendar/terms/${term.id}`, {
      name: term.name.trim(), start_date: term.start_date ?? '', end_date: term.end_date ?? '',
    }).subscribe({
      next: () => { this.busy.set(null); this.toast.success(`${term.name} saved`); this.refresh(); },
      error: (e) => { this.busy.set(null); this.toast.error(e?.error?.error || 'Could not save the term'); },
    });
  }

  makeCurrent(term: CalTerm): void {
    this.busy.set(term.id);
    this.api.put<CalTerm>(`/backend/school/academic-calendar/terms/${term.id}`, {is_current: true}).subscribe({
      next: () => { this.busy.set(null); this.toast.success(`${term.name} is now the current term`); this.refresh(); },
      error: (e) => { this.busy.set(null); this.toast.error(e?.error?.error || 'Could not change the current term'); },
    });
  }

  startSession(): void {
    const name = this.nextName().trim();
    if (!name) { this.toast.error('Name the session, e.g. 2027/2028'); return; }
    if (!window.confirm(`Start the ${name} session? Its First Term becomes the current term for everyone.`)) return;
    this.starting.set(true);
    this.api.post<any>('/backend/school/academic-calendar/sessions', {name}).subscribe({
      next: () => { this.starting.set(false); this.toast.success(`${name} started`); this.refresh(); },
      error: (e) => { this.starting.set(false); this.toast.error(e?.error?.error || 'Could not start the session'); },
    });
  }

  private refresh(): void {
    this.load();
    this.termContext.reload(); // keep the top-bar switcher in step
  }
}
