import { Component, computed, effect, ElementRef, EventEmitter, input, OnInit, Output, signal, ViewChild } from '@angular/core';
import { LearnoButton } from "../../../common/learno-button/learno-button";
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { LearnoInput } from '../../../common/learno-input/learno-input';
import { IInputOption, LearnoSelect } from '../../../common/learno-select/learno-select';
import { ApiService } from '../../../common/service/api.service';
import { ToastrService } from 'ngx-toastr';

@Component({
  selector: 'app-content-package-form',
  imports: [LearnoButton, ReactiveFormsModule, LearnoInput, LearnoSelect],
  templateUrl: './content-package-form.html',
  styleUrl: './content-package-form.css'
})
export class ContentPackageForm implements OnInit {


  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService
  ){
    
    effect(() => {
      const sel = this.select();
      if (!sel || Object.keys(sel).length === 0) return;

      // Normalize subject to match select options value
      const rawSubject = (sel as any).subjectArea ?? (sel as any).subject ?? '';
      const subjectNorm = (() => {
        const lower = String(rawSubject).toLowerCase();
        const byValue = this.subjectOptions.find(o => String(o.value).toLowerCase() === lower);
        if (byValue) return byValue.value;
        const byLabel = this.subjectOptions.find(o => String(o.label).toLowerCase() === lower);
        return byLabel ? byLabel.value : rawSubject;
      })();

      // Normalize grade to match select options value
      const rawGrade = (sel as any).gradeLevel ?? (sel as any).grade ?? '';
      const gradeNorm = (() => {
        const lower = String(rawGrade).toLowerCase();
        const byValue = this.gradeOptions.find(o => String(o.value).toLowerCase() === lower);
        if (byValue) return byValue.value;
        const byLabel = this.gradeOptions.find(o => String(o.label).toLowerCase() === lower);
        return byLabel ? byLabel.value : rawGrade;
      })();

      // Derive content ids from either contentIds or contents array
      const contentIdsNorm = (() => {
        if (Array.isArray((sel as any).contentIds)) return (sel as any).contentIds;
        if (Array.isArray((sel as any).contents)) {
          return (sel as any).contents
            .map((c: any) => c?.id)
            .filter((id: any) => typeof id === 'number');
        }
        return [] as number[];
      })();

      const patch = {
        name: (sel as any).name ?? (sel as any).title ?? '',
        packageType: (sel as any).packageType ?? (sel as any).type ?? 'subject_pack',
        description: (sel as any).description ?? '',
        subjectArea: subjectNorm,
        gradeLevel: gradeNorm,
        price: (sel as any).price != null ? String((sel as any).price) : '',
        durationMonths: (sel as any).durationMonths != null ? String((sel as any).durationMonths) : '',
        contentIds: contentIdsNorm,
        isActive: (sel as any).isActive != null ? Boolean((sel as any).isActive) : true
      };

      this.form.patchValue(patch as any);
      this.isEdit.set(Boolean((sel as any).id));
    });
  }
  ngOnInit(): void {
    // Load grade levels (classes)
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

    // Load subjects from the platform catalogue (packages scope to these).
    this.apiSrv.get('/backend/catalog/subjects').subscribe({
      next: (data: any[]) => {
        const arr = Array.isArray(data) ? data : [];
        this.catalogSubjects = arr;
        this.subjectOptions = arr.map((s: any) => ({
          label: String(s.name || 'Unknown'),
          value: String(s.name ?? '')
        }));
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to load subjects');
      }
    });
    // Sync signals with form controls to support conditional filtering
    const pkgCtrl = this.form.get('packageType');
    const subjCtrl = this.form.get('subjectArea');
    const gradeCtrl = this.form.get('gradeLevel');
    if (pkgCtrl) {
      this.selectedPackageType.set((pkgCtrl.value as any) || 'subject_pack');
      pkgCtrl.valueChanges.subscribe(val => this.selectedPackageType.set((val as any) || 'subject_pack'));
    }
    if (subjCtrl) {
      this.selectedSubjectArea.set(String(subjCtrl.value || ''));
      subjCtrl.valueChanges.subscribe(val => this.selectedSubjectArea.set(String(val || '')));
    }
    if (gradeCtrl) {
      this.selectedGradeLevel.set(String(gradeCtrl.value || ''));
      gradeCtrl.valueChanges.subscribe(val => this.selectedGradeLevel.set(String(val || '')));
    }
  }

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

  contents = input<any[]>([]);
  contentSearch = signal<string>('');
  @ViewChild('closebtn', { static: false }) closebtn!: any;
  isSubmitting = signal(false);
  isEdit = signal<boolean>(false);

  // Signals to reflect current selection for conditional filtering
  selectedPackageType = signal<'full_access'|'subject_pack'|'class_pack'>('subject_pack');
  selectedSubjectArea = signal<string>('');
  selectedGradeLevel = signal<string>('');

  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;
  select = input<{ [key:string]: string }>({})





    typeOptions: IInputOption[] = [
      { value: 'full_access', label: 'Full Access' },
      { value: 'subject_pack', label: 'Subject Pack' },
      { value: 'class_pack', label: 'Class Pack' },
    ];
  
    subjectOptions: IInputOption[] = [
      // Populated from /backend/catalog/subjects with name as label/value
    ];
    catalogSubjects: any[] = [];
  
    gradeOptions: IInputOption[] = [
      // Populated from /backend/school/classes with name as label and id as value
    ];

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

  onCreate() {
    if (this.form.invalid){
      this.toastSrv.error('Please fill in all required fields');
      return
    };
    this.isSubmitting.set(true);
    const f = this.form.value as any;
    // Parse price and duration as numbers
    const priceNum = Number(f.price);
    const durationNum = Number(f.durationMonths);
    const catalog = this.catalogSubjects.find((c: any) => String(c.name) === String(f.subjectArea));
    const payload = {
      name: f.name,
      type: f.packageType, // API expects 'type'
      description: f.description || '',
      subjectArea: f.subjectArea,
      catalog_subject_id: catalog?.id ?? null,
      gradeLevel: f.gradeLevel,
      price: isNaN(priceNum) ? null : priceNum,
      durationMonths: isNaN(durationNum) ? null : durationNum,
      contentIds: Array.isArray(f.contentIds) ? f.contentIds : [],
      isActive: !!f.isActive
    };
    this.apiSrv.post('/backend/content/packages', payload).subscribe({
      next: (resp: any) => {
        this.toastSrv.success('Package created successfully');
        this.form.reset({ name: '', packageType: 'subject_pack', description: '', subjectArea: '', gradeLevel: '', price: '', durationMonths: '', contentIds: [], isActive: true });
        this.closebtn?.nativeElement?.click(); 
      },
      error: (err) => {
        console.error(err);
        this.toastSrv.error('Failed to create package');
        this.isSubmitting.set(false);
      },
      complete: () => {
        this.isSubmitting.set(false);
      }
    });
  }

    // Content selection helpers
  filteredContents = computed(() => {
    const term = this.contentSearch().toLowerCase().trim();
    const pkgType = this.selectedPackageType();
    const subjVal = this.selectedSubjectArea();
    const gradeVal = this.selectedGradeLevel();

    let list = this.contents();

    // Conditional filtering by package type
    if (pkgType === 'subject_pack' && subjVal) {
      const subjLabel = String(subjVal).toLowerCase();
      console.log(list, subjLabel)
      list = list.filter((c: any) => String(c.subjectArea || '').toLowerCase() === subjLabel);
    } else if (pkgType === 'class_pack' && gradeVal) {
      const gradeLabel = (this.gradeOptions.find(o => String(o.value) === String(gradeVal))?.label || '').toLowerCase();
      if (gradeLabel) {
        list = list.filter((c: any) => String(c.gradeLevel || '').toLowerCase() === gradeLabel);
      }
    }

    // Apply text search last
    if (!term) return list;
    return list.filter((c: any) =>
      String(c.title || '').toLowerCase().includes(term)
      || String(c.subjectArea || '').toLowerCase().includes(term)
      || String(c.gradeLevel || '').toLowerCase().includes(term)
      || String(c.contentType || '').toLowerCase().includes(term)
    );
  });

    onEdit() {
    this.isEdit.set(true);
    this.apiSrv.put("/backend/content/packages", { ...this.form.value, id: this.select()['id'] })
      .subscribe({
        next: (res) => {
          this.form.reset();
          this.toastSrv.success("updated successfully")
          this.submitted.emit({ success: true });
        },
        error: (err) => {
          this.isEdit.set(false);
          this.toastSrv.error("failed to submit")
          console.log(err)
        },
        complete: () => {
          this.isEdit.set(false);
          this.autoCloseBtn.nativeElement.click();
        }
      })
  }
}
