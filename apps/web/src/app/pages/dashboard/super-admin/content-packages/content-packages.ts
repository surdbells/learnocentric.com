import { Component, OnInit, signal, computed, ViewChild } from '@angular/core';
import { PageHeader } from '../../../../common/layout/page-header/page-header';
import { TableSearch } from '../../../../components/table-search/table-search';
import { DataTable } from '../../../../components/data-table/data-table';
import { DataTableNumbering } from '../../../../components/data-table-numbering/data-table-numbering';
import { SkeletonLoader } from '../../../../common/skeleton-loader/skeleton-loader';
import { ApiService } from '../../../../common/service/api.service';
import { ToastrService } from 'ngx-toastr';
import { LearnoModal } from '../../../../components/learno-modal/learno-modal';
import { LearnoButton } from '../../../../common/learno-button/learno-button';
import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';
import { IInputOption } from '../../../../common/learno-select/learno-select';
import { ContentPackageForm } from "../../../../components/forms/content-package-form/content-package-form";
import { LearnoOffset } from "../../../../components/learno-offset/learno-offset";
import { RichText } from '../../../../common/rich-editor/rich-text';

@Component({
  selector: 'app-super-admin-content-packages',
  standalone: true,
  imports: [PageHeader, TableSearch, DataTable, DataTableNumbering, SkeletonLoader, LearnoModal, LearnoButton, ReactiveFormsModule, ContentPackageForm, LearnoOffset, RichText],
  templateUrl: './content-packages.html',
  styleUrl: './content-packages.css'
})
export class SuperAdminContentPackages implements OnInit {



  isLoading = signal(false);
  packages = signal<any[]>([]);
  searchTerm = signal('');
  filterType = signal('');
  // Subjects and contents sources
  subjects = signal<any[]>([]);
  contents = signal<any[]>([]);
  contentSearch = signal<string>('');
  anchorSelector = signal<string>('');
  selectedPackage = signal<any | null>(null);



  @ViewChild('closebtn', { static: false }) closebtn!: any;
  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  isSubmitting = signal(false);
  form = new FormGroup({
    name: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    packageType: new FormControl<'full_access'|'subject_pack'|'class_pack'>('subject_pack', { nonNullable: true, validators: [Validators.required] }),
    description: new FormControl<string>(''),
    subjectArea: new FormControl<string>('', { nonNullable: true, validators: [Validators.required] }),
    gradeLevel: new FormControl<string>('', { nonNullable: true, validators: [Validators.required] }),
    price: new FormControl<string>('', { nonNullable: true, validators: [Validators.required] }),
    durationMonths: new FormControl<string>('', { nonNullable: true, validators: [Validators.required] }),
    contentIds: new FormControl<number[]>([], { nonNullable: true, validators: [Validators.required] }),
    isActive: new FormControl<boolean>(true, { nonNullable: true })
  });

  typeOptions: IInputOption[] = [
    { value: 'full_access', label: 'Full Access' },
    { value: 'subject_pack', label: 'Subject Pack' },
    { value: 'class_pack', label: 'Class Pack' },
  ];

  subjectOptions: IInputOption[] = [];

  gradeOptions: IInputOption[] = [
    // Populated from /backend/school/classes with name as label and id as value
  ];

  filterKeys = ['Type'];
  filterValues = [
    this.typeOptions.map(o => ({ label: o.label, value: `type:${o.value}` }))
  ];

  filteredPackages = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    const type = this.filterType();
    return this.packages().filter((p: any) =>
      (
        !term
        || String(p.name || '').toLowerCase().includes(term)
        || String(p.type || '').toLowerCase().includes(term)
        || String(p.description || '').toLowerCase().includes(term)
      )
      && (
        !type
        || String(p.type || '').toLowerCase() === String(type)
      )
    );
  });

  // Pagination state
  pageSize = signal<number>(10);
  currentPage = signal<number>(1);

  onPageChange(p: number) {
    this.currentPage.set(Math.max(1, Number(p || 1)));
  }

  constructor(private readonly apiSrv: ApiService, private readonly toastSrv: ToastrService) {}

  ngOnInit(): void {
    this.isLoading.set(true);
    // Load packages
    this.apiSrv.get('/backend/content/packages').subscribe({
      next: (data) => {
        const list = Array.isArray(data) ? data : (Array.isArray((data as any)?.items) ? (data as any).items : []);
        const normalized = list.map((i: any, idx: number) => ({
          id: i.id ?? idx + 1,
          name: i.name ?? i.title ?? 'Unnamed Package',
          type: i.package_type ?? i.package_type ?? '',
          description: i.description ?? '',
          createdAt: i.createdAt ?? i.created_at ?? i.created_on ?? null,
          subjectArea: i.subjectArea ?? i.subject_area ?? '',
          gradeLevel: i.grade_level ?? i.grade ?? '',
          price: i.price ?? i.cost ?? null,
          durationMonths: i.durationMonths ?? i.duration_months ?? null,
          isActive: Boolean(i.isActive ?? i.active ?? true) ,
          contentsCount: Array.isArray(i.contentIds)
            ? i.contentIds.length
            : (Array.isArray(i.contents)
              ? i.contents.length
              : (typeof i.content_count === 'number' ? i.content_count : (i.content_count ?? 0)))
        }));
        this.packages.set(normalized);
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load content packages');
      },
      complete: () => {
        this.isLoading.set(false);
      }
    });

    // Load subjects for options (from subject listing)
    this.apiSrv.get('/backend/school/subjects').subscribe({
      next: (data: any[]) => {
        const arr = Array.isArray(data) ? data : [];
        this.subjects.set(arr);
        this.subjectOptions = arr.map((s: any) => ({
          label: String(s.name || s.code || 'Unknown'),
          value: String(s.code || s.name || '').toLowerCase().replace(/\s+/g, '_')
        }));
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load subjects');
      }
    });

    // Load content library for selection
    this.apiSrv.get('/backend/content/library').subscribe({
      next: (data: any) => {
        const list = Array.isArray(data) ? data : (Array.isArray((data as any)?.items) ? (data as any).items : []);
        const normalized = list.map((c: any, idx: number) => ({
          id: c.id ?? idx + 1,
          title: c.title ?? 'Untitled',
          subjectArea: c.subjectArea ?? c.subject_area ?? '',
          gradeLevel: c.gradeLevel ?? c.grade ?? '',
          contentType: c.contentType ?? c.type ?? '',
          isActive: Boolean(c.isActive ?? c.active ?? true),
        }));
        this.contents.set(normalized);
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load content library');
      }
    });

    // Load classes for grade options (use name as label, id as value)
    this.apiSrv.get('/backend/school/classes').subscribe({
      next: (data: any[]) => {
        const arr = Array.isArray(data) ? data : [];
        this.gradeOptions = arr.map((c: any) => ({
          label: String(c.name || c.class_name || c.className || 'Unknown'),
          value: String(c.id ?? '')
        }));
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load classes');
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
    this.currentPage.set(1);
  }

  onCreate() {
    if (this.form.invalid) return;
    this.isSubmitting.set(true);
    const f = this.form.value as any;
    // Parse price and duration as numbers
    const priceNum = Number(f.price);
    const durationNum = Number(f.durationMonths);
    const payload = {
      name: f.name,
      type: f.packageType, // API expects 'type'
      description: f.description || '',
      subjectArea: f.subjectArea,
      gradeLevel: f.gradeLevel,
      price: isNaN(priceNum) ? null : priceNum,
      durationMonths: isNaN(durationNum) ? null : durationNum,
      contentIds: Array.isArray(f.contentIds) ? f.contentIds : [],
      isActive: !!f.isActive
    };
    this.apiSrv.post('/backend/content/packages', payload).subscribe({
      next: (resp: any) => {
        this.toastSrv.success('Package created');
        const item = {
          id: resp?.id ?? resp?.packageId ?? (this.packages().length + 1),
          name: resp?.name ?? f.name,
          type: resp?.type ?? f.packageType,
          description: resp?.description ?? f.description ?? '',
          createdAt: resp?.createdAt ?? new Date().toISOString(),
          isActive: Boolean(resp?.isActive ?? f.isActive),
          subjectArea: resp?.subjectArea ?? f.subjectArea,
          gradeLevel: resp?.gradeLevel ?? f.gradeLevel,
          price: resp?.price ?? priceNum,
          durationMonths: resp?.durationMonths ?? durationNum,
          contentIds: resp?.contentIds ?? f.contentIds,
          contentsCount: Array.isArray(resp?.contentIds) ? resp.contentIds.length : (Array.isArray(f.contentIds) ? f.contentIds.length : 0)
        };
        this.packages.set([item, ...this.packages()]);
        this.form.reset({ name: '', packageType: 'subject_pack', description: '', subjectArea: '', gradeLevel: '', price: '', durationMonths: '', contentIds: [], isActive: true });
        try { this.closebtn?.nativeElement?.click(); } catch {}
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to create package');
      },
      complete: () => {
        this.isSubmitting.set(false);
      }
    });
  }

  // Content selection helpers
  filteredContents = computed(() => {
    const term = this.contentSearch().toLowerCase().trim();
    if (!term) return this.contents();
    return this.contents().filter((c: any) =>
      String(c.title || '').toLowerCase().includes(term)
      || String(c.subjectArea || '').toLowerCase().includes(term)
      || String(c.gradeLevel || '').toLowerCase().includes(term)
      || String(c.contentType || '').toLowerCase().includes(term)
    );
  });

  toggleContentSelection(id: number, checked: boolean) {
    const curr = Array.isArray(this.form.value.contentIds) ? [...(this.form.value.contentIds as number[])] : [];
    const set = new Set<number>(curr);
    if (checked) set.add(id); else set.delete(id);
    this.form.patchValue({ contentIds: Array.from(set) });
  }

  onContentSearchInput(event: Event) {
    const val = (event.target as HTMLInputElement)?.value || '';
    this.contentSearch.set(val);
  }

  
  handleCloseOffset() {
    this.selectedPackage.set(null);
    this.form.reset();
    this.isSubmitting.set(false);
    this.anchorSelector.set('');
  } 


  deletePackage() {
    const sel = this.selectedPackage();
    if (!sel || !sel.id) {
      this.toastSrv.error('No package selected');
      return;
    }


    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/content/packages?id=${this.selectedPackage()['id']}`)
      .subscribe({
        next: () => {
          this.toastSrv.success('Package deleted successfully');  

          this.packages.set(
            this.packages().filter(e => e.id !== this.selectedPackage()['id'])
          );
          this.selectedPackage.set(null);
          this.handleCloseOffset();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to delete package');
          this.isLoading.set(false);
        }
      });

      this.offsetCmp.close();
  }

  onPreview(evt: { anchorSelector: string; row: any }) {
    this.selectedPackage.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');

    //load resources with its id
    this.apiSrv.get(`/backend/content/packages/${this.selectedPackage()['id']}`)
      .subscribe({
        next: (resp: any) => {
          const normalized = {
            id: resp?.id ?? resp?.packageId ?? this.selectedPackage()['id'],
            name: resp?.name ?? this.selectedPackage()['name'],
            type: resp?.type ?? this.selectedPackage()['type'],
            description: resp?.description ?? this.selectedPackage()['description'],
            createdAt: resp?.createdAt ?? this.selectedPackage()['createdAt'],
            isActive: Boolean(resp?.isActive ?? this.selectedPackage()['isActive']),
            subjectArea: resp?.subjectArea ?? this.selectedPackage()['subjectArea'],
            gradeLevel: resp?.gradeLevel ?? this.selectedPackage()['gradeLevel'],
            price: resp?.price ?? this.selectedPackage()['price'],
            durationMonths: resp?.durationMonths ?? this.selectedPackage()['durationMonths'],
            contentIds: resp?.content?.map((e: any) => e.id),
            contentsCount: resp.content_count //Array.isArray(resp?.contentIds) ? resp.contentIds.length : (Array.isArray(this.selectedPackage()['contentIds']) ? this.selectedPackage()['contentIds'].length : 0)
          }
          console.log(normalized);
          this.selectedPackage.set({
            ...normalized
          });
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to load resources');
        }
      });
  }

    handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.isLoading.set(true);
      this.apiSrv.get("/backend/content/packages")
        .subscribe({
          next: (data) => {
            this.packages.set(data);
          },
          error: (error) => {
            this.toastSrv.error("Error fetching packages", "Error");
            this.isLoading.set(false);
          },
          complete: () => {
            this.isLoading.set(false);
          }
        })
    }
  }

} 