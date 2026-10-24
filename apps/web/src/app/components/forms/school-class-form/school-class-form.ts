import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-school-class-form',
  imports: [
    LearnoInput,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './school-class-form.html',
  styleUrl: './school-class-form.css'
})
export class SchoolClassForm {

  isLoading = signal<boolean>(false);

  select = input<{ [key:string]: string }>({})

  isEdit = signal<boolean>(false);
  /** Teachers that can be set as the class teacher. */
  teachers = signal<{ id: number; name: string }[]>([]);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

  form = new FormGroup({
    // Level + section make the class label (e.g. "JSS 1 A"), so the level is text,
    // not a number — Nigerian levels like "JSS 1" / "Primary 4" aren't numeric.
    gradeLevel: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    section: new FormControl('', {nonNullable: true}),
    classTeacherId: new FormControl<number | null>(null),
  })
  action = input<string>('');

  /** Live preview of the class name the level + section will produce. */
  get previewName(): string {
    return `${this.form.get('gradeLevel')?.value ?? ''} ${this.form.get('section')?.value ?? ''}`.trim();
  }

  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    this.apiSrv.get<any>('/backend/school/teachers').subscribe({
      next: (r) => {
        const rows = Array.isArray(r) ? r : (r?.data ?? []);
        this.teachers.set(rows
          .map((x: any) => ({id: x.id, name: x.name || `${x.first_name ?? ''} ${x.last_name ?? ''}`.trim()}))
          .filter((x: {id: number; name: string}) => x.id && x.name));
      },
    });

    effect(() => {
      const s: any = this.select();
      if (!s || !s['id']) { this.form.reset(); this.isEdit.set(false); return; }

      this.form.reset();
      this.form.patchValue({
        gradeLevel: s['grade_level'] ?? s['level'] ?? '',
        section: s['arm'] ?? s['section'] ?? '',
        classTeacherId: s['class_teacher_id'] ?? null,
      }, { emitEvent: false });
      this.isEdit.set(true);
    })
  }

  onSubmit() {
    this.isLoading.set(true);
    if (this.form.valid) {
      this.apiSrv.post("/backend/school/classes", this.form.value).subscribe(
        {
          next: () => {
            this.form.reset();
            this.toastService.success("submitted successfully");
            this.submitted.emit({success: true});
            this.isLoading.set(false);
          },
          error: () => {
            this.isLoading.set(false);
            this.toastService.error("failed to submit")
          },
          complete: () => {
            this.isLoading.set(false);
            this.autoCloseBtn.nativeElement.click();
          }
        }
      )
    } else {
      this.isLoading.set(false);
      this.toastService.error("Please fill in all required fields correctly")
    }
  }

  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/school/classes", { ...this.form.value, id: this.select()['id'] })
      .subscribe({
        next: () => {
          this.form.reset();
          this.toastService.success("updated successfully")
          this.submitted.emit({ success: true });
        },
        error: () => {
          this.isLoading.set(false);
          this.toastService.error("failed to submit")
        },
        complete: () => {
          this.isLoading.set(false);
          this.autoCloseBtn.nativeElement.click();
        }
      })
  }
}
