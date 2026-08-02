import {Component, computed, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from '../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../components/data-table-numbering/data-table-numbering';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../components/table-search/table-search';
import {LearnoButton} from '../../../../common/learno-button/learno-button';
import {ApiService} from '../../../../common/service/api.service';
import {SkeletonLoader} from '../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../components/learno-offset/learno-offset';
import {ToastrService} from 'ngx-toastr';
import { Router, RouterLink } from '@angular/router';
import { DatePipe } from '@angular/common';
import { LearnoModal } from "../../../../components/learno-modal/learno-modal";
import { InstitutionForm } from "../../../../components/forms/institution-form/institution-form";
import { FormsModule } from '@angular/forms';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../common/ui';

@Component({
  selector: 'app-super-admin-institutions',
  standalone: true,
  imports: [
    DataTable,
    DataTableNumbering,
    PageHeader,
    TableSearch,
    LearnoButton,
    // Loader,
    SkeletonLoader,
    LearnoOffset,
    DatePipe,
    RouterLink,
    LearnoModal,
    InstitutionForm,
    FormsModule,
    KpiStrip,
    TabBar,
],
  templateUrl: './institutions.html',
  styleUrl: './institutions.css'
})
export class SuperAdminInstitutions implements OnInit {

  isLoading = signal(false);
  institutions = signal<any[]>([]);
  isEdit = signal<boolean>(false);
  selectedInstitutionId = signal<string | null>(null);
  



  selectedInstitution = signal<any | null>(null);
  anchorSelector = signal<string>('');
  searchTerm = signal<string>('');
  typeTab = signal<string>('all');

  readonly kpis = computed<KpiItem[]>(() => {
    const i = this.institutions();
    const byType = (t: string) => i.filter(x => x.type === t).length;
    const active = i.filter(x => (x.status ?? 'active') === 'active').length;
    return [
      {label: 'Total institutions', value: i.length, icon: 'apartment', tone: 'primary'},
      {label: 'Schools', value: byType('school'), icon: 'school', tone: 'info'},
      {label: 'Academies', value: byType('academy'), icon: 'workspace_premium', tone: 'warning'},
      {label: 'Active', value: active, icon: 'check_circle', tone: 'success'},
    ];
  });

  readonly typeTabs = computed<TabItem[]>(() => {
    const i = this.institutions();
    return [
      {key: 'all', label: 'All', count: i.length},
      {key: 'school', label: 'Schools', count: i.filter(x => x.type === 'school').length},
      {key: 'academy', label: 'Academies', count: i.filter(x => x.type === 'academy').length},
    ];
  });

  packages = signal<any[]>([]);
  assignPackageId = signal<string>('');
  assigning = signal<boolean>(false);
  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;
  @ViewChild('closebtn', { static: false }) closebtn!: any;
  

  filteredInstitutions = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    const typeFilter = this.typeTab();
    return this.institutions().filter((i: any) => {
      if (typeFilter !== 'all' && i.type !== typeFilter) return false;
      if (!term) return true;
      const name = (i.name || '').toString().toLowerCase();
      const email = (i.email || '').toString().toLowerCase();
      const phone = (i.phone || '').toString().toLowerCase();
      const type = (i.type || '').toString().toLowerCase();
      return name.includes(term) || email.includes(term) || phone.includes(term) || type.includes(term);
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
    private readonly toastService: ToastrService,
    private readonly router: Router,
  ) {}

  ngOnInit(): void {
    this.apiSrv.get('/backend/content/packages').subscribe({
      next: (data: any) => this.packages.set(Array.isArray(data) ? data : (data?.data ?? [])),
      error: () => {},
    });
    this.isLoading.set(true);
    this.apiSrv.get('/backend/admin/institutions')
      .subscribe({
        next: (data) => {
          const list = Array.isArray(data) ? data : (Array.isArray(data?.institutions) ? data.institutions : []);
          const normalized = list.map((i: any, idx: number) => ({
            id: i.id ?? i.institutionId ?? idx + 1,
            name: i.name ?? i.institutionName ?? 'Unknown',
            type: i.institutionType ?? i.type ?? 'school',
            email: i.email ?? i.contactEmail ?? '',
            phone: i.phone ?? i.contactPhone ?? '',
            createdAt: i.createdAt ?? i.created_date ?? i.created_on ?? null,
            address: i.address ?? '',
            status: i.status ?? 'active',
            logoUrl: i.logo_url,
            primaryColor: i.primary_color,
            secondaryColor: i.secondary_color,
          }));
          this.institutions.set(normalized);
        },
        error: (err) => {
          this.toastService.error('Failed to load institutions');
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.apiSrv.get(`/backend/admin/institutions/${evt.row.id}`)
      .subscribe({
        next: (data) => {
          evt.row = data;

          this.selectedInstitution.set(evt.row);
          this.assignPackageId.set(evt.row?.assigned_package_id ? String(evt.row.assigned_package_id) : '');
          this.anchorSelector.set(evt.anchorSelector || '');
          this.selectedInstitutionId.set(evt.row.id);
          this.isEdit.set(true);
        },
        error: (err) => {
          this.toastService.error('Failed to load institution details');
        }
      });


  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
    this.currentPage.set(1);
  }

  handleCloseOffset() {
    this.selectedInstitution.set(null);
    this.anchorSelector.set('');
  }

  assignPackage() {
    const inst = this.selectedInstitution();
    if (!inst) return;
    this.assigning.set(true);
    const packageId = this.assignPackageId() ? Number(this.assignPackageId()) : null;
    this.apiSrv.post('/backend/content/assign', {institution_id: inst.id, package_id: packageId}).subscribe({
      next: (res: any) => {
        this.toastService.success(packageId ? 'Package assigned' : 'Package removed', 'Success');
        this.selectedInstitution.set({...inst, assigned_package_id: res?.assigned_package_id ?? null});
        this.assigning.set(false);
      },
      error: (e) => { this.toastService.error(e?.error?.error || 'Could not assign the package'); this.assigning.set(false); },
    });
  }

  goToOnboard() {
    this.router.navigate(['/super-admin/onboard']);
  }

  handleSuccessSubmit($event: { success: boolean }) {
    this.offsetCmp.close();
    this.closebtn?.nativeElement.click();
  }

deleteContent() {
  if(this.selectedInstitution()) {
    this.apiSrv.delete(`/backend/admin/institutions/${this.selectedInstitutionId()}`).subscribe({
      next: (data) => {
        this.toastService.success("Institution deleted successfully", "Success");
        this.offsetCmp?.close();
        this.ngOnInit();
      },
      error: (error) => {
        this.toastService.error("Error deleting institution", "Error");
      }
    })
  }
}
}