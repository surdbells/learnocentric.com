import { Component, OnInit, computed, signal, ViewChild } from '@angular/core';
import { PageHeader } from '../../../../common/layout/page-header/page-header';
import { TableSearch } from '../../../../components/table-search/table-search';
import { DataTable } from '../../../../components/data-table/data-table';
import { DataTableNumbering } from '../../../../components/data-table-numbering/data-table-numbering';
import { LearnoModal } from '../../../../components/learno-modal/learno-modal';
import { LearnoButton } from '../../../../common/learno-button/learno-button';
import { SkeletonLoader } from '../../../../common/skeleton-loader/skeleton-loader';
import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';
import { LearnoInput } from '../../../../common/learno-input/learno-input';
import { LearnoSelect, IInputOption } from '../../../../common/learno-select/learno-select';
import { ApiService } from '../../../../common/service/api.service';
import { ToastrService } from 'ngx-toastr';
import { DatePipe } from '@angular/common';
import { UtilService } from '../../../../common/service/util.service';
import { LearnoOffset } from "../../../../components/learno-offset/learno-offset";
import {Icon} from '../../../../common/icon/icon';

@Component({
  selector: 'app-super-admin-content-library',
  standalone: true,
  imports: [Icon, 
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoModal,
    LearnoButton,
    SkeletonLoader,
    ReactiveFormsModule,
    LearnoInput,
    LearnoSelect,
    DatePipe,
    LearnoOffset
],
  templateUrl: './content-library.html',
  styleUrl: './content-library.css',
})
export class SuperAdminContentLibrary implements OnInit {


  isLoading = signal(false);
  contents = signal<any[]>([]);
  classes = signal<any[]>([]);
  gradeNameById: Record<string, string> = {};
  isEdit = signal(false)

  searchTerm = signal<string>('');
  filterType = signal<string>('');
  filterSubject = signal<string>('');
  filterGrade = signal<string>('');

  selectedContent = signal<any | null>(null);
  anchorSelector = signal<string>('');

  // Dynamic counts by content type
  countsByType = computed(() => {
    const counts: Record<string, number> = {};
    for (const c of this.contents()) {
      const t = String(c.contentType || '').toLowerCase();
      if (!t) continue;
      counts[t] = (counts[t] || 0) + 1;
    }
    return counts;
  });

  // Upload form refs
  @ViewChild('closebtn', { static: false }) closebtn!: any;
  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  isUploading = signal(false);
  form = new FormGroup({
    title: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    subjectArea: new FormControl(''),
    gradeLevel: new FormControl(''),
    contentType: new FormControl(''),
    isPremium: new FormControl(false),
    file: new FormControl<File | null>(null),
    description: new FormControl<string>(''),
    difficultyLevel: new FormControl(''),
    tags: new FormControl<string[]>([]),
  });

    levelOptions: IInputOption[] = [
    { value: 'beginner', label: 'Begginner' },
    { value: 'intermediate', label: 'Intermediate' },
    { value: 'advance', label: 'Advance' },
  ];

  typeOptions: IInputOption[] = [
    { value: 'document', label: 'Document' },
    { value: 'video', label: 'Video' },
    { value: 'assignment', label: 'Assignment' },
    { value: 'quiz', label: 'Quiz' },
    { value: 'interactive', label: 'Interactive' },
  ];

  subjectOptions: IInputOption[] = [];

  gradeOptions: IInputOption[] = [];

  filterKeys = ['Type', 'Subject', 'Grade'];
  filterValues = [
    this.typeOptions.map(o => ({ label: o.label, value: `type:${o.value}` })),
    this.subjectOptions.map(o => ({ label: o.label, value: `subject:${o.value}` })),
    this.gradeOptions.map(o => ({ label: o.label, value: `grade:${o.value}` })),
  ];

  filteredContents = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    const type = this.filterType();
    const subject = this.filterSubject();
    const grade = this.filterGrade();

    return this.contents().filter((c: any) => {
      const matchesTerm = !term
        || String(c.title || '').toLowerCase().includes(term)
        || String(c.subjectArea || '').toLowerCase().includes(term)
        || String(c.gradeLevel || '').toLowerCase().includes(term)
        || String(c.contentType || '').toLowerCase().includes(term);

      const matchesType = !type || String(c.contentType || '').toLowerCase() === String(type);
      const matchesSubject = !subject || String(c.subjectArea || '').toLowerCase() === String(subject);
      const matchesGrade = !grade || String(c.gradeId || '').toLowerCase() === String(grade);

      return matchesTerm && matchesType && matchesSubject && matchesGrade;
    });
  });

  // Pagination state
  pageSize = signal<number>(10);
  currentPage = signal<number>(1);

  onPageChange(p: number) {
    this.currentPage.set(Math.max(1, Number(p || 1)));
  }

  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService
  ) {}

  ngOnInit(): void {
    this.loadClasses();
    this.loadSubjects();
    this.loadContents();
  }

  loadClasses() {
    this.apiSrv.get('/backend/school/classes').subscribe({
      next: (data: any) => {
        const list = Array.isArray(data) ? data : (Array.isArray((data as any)?.items) ? (data as any).items : []);
        const options: IInputOption[] = list.map((c: any) => ({
          value: String(c.id ?? c.classId ?? c.code ?? ''),
          label: String(c.name ?? c.className ?? ''),
        })).filter((o: any) => o.value && o.label);
        this.gradeOptions = options;
        this.classes.set(list);
        this.gradeNameById = options.reduce((acc, o) => { acc[o.value] = o.label; return acc; }, {} as Record<string, string>);
        // refresh filter values to include dynamic grade options
        this.filterValues = [
          this.typeOptions.map(o => ({ label: o.label, value: `type:${o.value}` })),
          this.subjectOptions.map(o => ({ label: o.label, value: `subject:${o.value}` })),
          this.gradeOptions.map(o => ({ label: o.label, value: `grade:${o.value}` })),
        ];
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load classes');
      }
    });
  }

  loadSubjects() {
    this.apiSrv.get('/backend/catalog/subjects').subscribe({
      next: (data: any) => {
        const list = Array.isArray(data) ? data : (Array.isArray((data as any)?.items) ? (data as any).items : []);
        const options: IInputOption[] = list.map((s: any) => ({
          value: String(s.name ?? ''),
          label: String(s.name ?? 'Unknown'),
        })).filter((o: any) => o.value && o.label);
        this.subjectOptions = options;
        // refresh filter values to include dynamic subject options
        this.filterValues = [
          this.typeOptions.map(o => ({ label: o.label, value: `type:${o.value}` })),
          this.subjectOptions.map(o => ({ label: o.label, value: `subject:${o.value}` })),
          this.gradeOptions.map(o => ({ label: o.label, value: `grade:${o.value}` })),
        ];
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load subjects');
      }
    });
  }

  loadContents() {
    this.isLoading.set(true);
    this.apiSrv.get('/backend/content/library')
      .subscribe({
        next: (data) => {
          const list = Array.isArray(data) ? data : (Array.isArray((data as any)?.items) ? (data as any).items : []);
          const normalized = list.map((i: any, idx: number) => {
            const rawId = i.gradeLevelId ?? i.classId ?? i.gradeId ?? i.grade_level_id ?? i.grade_class_id;
            const rawName = i.gradeLevel ?? i.grade ?? i.className;
            const gradeId = rawId != null ? String(rawId) : '';
            const gradeLevelName = gradeId && this.gradeNameById[gradeId] ? this.gradeNameById[gradeId] : String(rawName || '');
            return {
              id: i.id ?? idx + 1,
              title: i.title ?? i.name ?? 'Untitled',
              contentType: i.contentType ?? i.content_type ?? 'document',
              subjectArea: i.subjectArea ?? i.subject_area ?? '',
              gradeId,
              gradeLevel: i.grade_level ?? gradeLevelName,
              isPremium: String(i.is_premium ?? i.isPremium ?? false),
              createdAt: i.createdAt ?? i.created_date ?? i.created_on ?? null,
            };
          });
          this.contents.set(normalized);
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to load content library');
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
    this.currentPage.set(1);
  }

  onFilter(value: string) {
    const v = String(value || '');
    const [key, raw] = v.split(':');
    const val = (raw || '').toLowerCase();
    if (!key) return;
    if (key === 'type') this.filterType.set(val);
    if (key === 'subject') this.filterSubject.set(val);
    if (key === 'grade') this.filterGrade.set(val);
    this.currentPage.set(1);
  }

  // Card click to filter by content type
  onTypeCardClick(type: string) {
    const t = String(type || '').toLowerCase();
    this.filterType.set(t);
    this.currentPage.set(1);
  }

  // Helper: icon name for material symbols based on type
  typeIcon(type: string): string {
    const t = String(type || '').toLowerCase();
    const map: Record<string, string> = {
      video: 'folder_open',
      document: 'news',
      interactive: 'folder',
      assignment: 'assignment',
      quiz: 'quiz'
    };
    return map[t] || 'folder';
  }

  // Helper: card color classes by type
  typeCardClass(type: string): string {
    const t = String(type || '').toLowerCase();
    const map: Record<string, string> = {
      video: 'bg-success bg-opacity-10',
      document: 'bg-danger bg-opacity-10',
      interactive: 'bg-success bg-opacity-10',
      assignment: 'bg-danger bg-opacity-10',
      quiz: 'bg-primary bg-opacity-10'
    };
    return `${map[t] || 'bg-success bg-opacity-10'} border-0 rounded-3 mb-4 file-for-dark`;
  }

  onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
      const file = input.files[0];
      this.form.patchValue({ file });
      this.form.get('file')?.updateValueAndValidity();
    }
  }

  onUpload() {
    if (this.form.invalid) {
      this.toastSrv.error('Please fill in all required fields');
      return
    };
    this.isUploading.set(true);

    const formData = new FormData();
    const f = this.form.value as any;
    formData.append('title', f.title);
    formData.append('subjectArea', f.subjectArea);
    // gradeLevel should be class id
    formData.append('gradeLevel', String(f.gradeLevel));
    formData.append('contentType', f.contentType);
    formData.append('description', f.description || '');
    formData.append('difficultyLevel', f.difficultyLevel || '');
    formData.append('tags', f.tags || '');
    if (f.file) formData.append('file', f.file);

    if(f.isPremium) formData.append('isPremium', 'true');
    // else formData.append('isPremium', 'false');



    this.apiSrv.post('/backend/content/library', formData)
      .subscribe({
        next: (resp: any) => {
          this.toastSrv.success('Content uploaded');
          this.form.reset({ title: '', subjectArea: '', gradeLevel: '', contentType: '', isPremium: false, file: null, description: '' });
          this.closebtn?.nativeElement?.click();
          this.loadContents();

        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Upload failed');
          this.isUploading.set(false);
        },
        complete: () => {
          this.isUploading.set(false);
        }
      });
  }

  handleCloseOffset() {
    this.selectedContent.set(null);
    this.form.reset();
    this.isEdit.set(false);
    this.anchorSelector.set('');
  } 

  onPreview(evt: { anchorSelector: string; row: any }) {
    this.selectedContent.set(evt.row);
    this.form.patchValue({
      title: evt.row.title,
      subjectArea: evt.row.subjectArea,
      gradeLevel: evt.row.gradeLevel,
      contentType: evt.row.contentType,
      isPremium: evt.row.isPremium,
      description: evt.row.description,
      difficultyLevel: evt.row.difficultyLevel,
      tags: evt.row.tags,
    });
    this.isEdit.set(true);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

    deleteContent() {
    const sel = this.selectedContent();
    if (!sel || !sel.id) {
      this.toastSrv.error('No content selected');
      return;
    }


    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/content/library?id=${this.selectedContent()['id']}`)
      .subscribe({
        next: () => {
          this.isLoading.set(false);
          this.toastSrv.success('Content deleted successfully');

          this.contents.set(
            this.contents().filter(e => e.id !== this.selectedContent()['id'])
          );
          this.selectedContent.set(null);
          this.handleCloseOffset();
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to delete content');
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });

      this.offsetCmp.close()
  }

    onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/content/library", { ...this.form.value, id: this.selectedContent()['id'] })
      .subscribe({
        next: (res) => {
          this.form.reset();
          this.toastSrv.success("updated successfully")
          this.offsetCmp.close();
          this.isEdit.set(false);
        },
        error: (err) => {
          this.isLoading.set(false);
          this.toastSrv.error("failed to submit")
          console.log(err)
        },
        complete: () => {
          this.isLoading.set(false);
          this.closebtn?.nativeElement?.click();
          this.offsetCmp.close();
        }
      })
  }
}