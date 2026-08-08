import {Component, computed, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {Router} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

@Component({
  selector: 'app-student',
  imports: [
    DataTable,
    DataTableNumbering,
    PageHeader,
    TableSearch,
    SkeletonLoader,
    KpiStrip,
    TabBar,
  ],
  templateUrl: './student.html',
  styleUrl: './student.css'
})
export class Student implements OnInit {

  isLoading = signal(false);
  students = signal<any[]>([]);
  searchTerm = signal<string>('');
  classTab = signal<string>('all');

  selectedResult = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.students();
    const active = s.filter(x => x.is_active).length;
    const classes = new Set(s.map(x => x.class_name).filter(Boolean)).size;
    return [
      {label: 'Total learners', value: s.length, icon: 'group', tone: 'primary'},
      {label: 'Active', value: active, icon: 'check_circle', tone: 'success'},
      {label: 'Classes', value: classes, icon: 'meeting_room', tone: 'info'},
      {label: 'Inactive', value: s.length - active, icon: 'cancel', tone: (s.length - active) ? 'warning' : 'secondary'},
    ];
  });

  readonly classTabs = computed<TabItem[]>(() => {
    const s = this.students();
    const classes = [...new Set(s.map(x => x.class_name).filter(Boolean))] as string[];
    return [
      {key: 'all', label: 'All classes', count: s.length},
      ...classes.map(c => ({key: c, label: c, count: s.filter(x => x.class_name === c).length})),
    ];
  });

  filterStudents = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    const cls = this.classTab();
    return this.students().filter((s: any) => {
      if (cls !== 'all' && s.class_name !== cls) return false;
      if (!term) return true;
      const fname = (s.first_name || '').toString().toLowerCase();
      const lname = (s.last_name || '').toString().toLowerCase();
      const cname = (s.class_name || '').toString().toLowerCase();
      return fname.includes(term) || lname.includes(term) || cname.includes(term);
    });
  });

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
    private readonly auth: AuthService
  ) { }

  ngOnInit(): void {
    this.isLoading.set(true);
    const user = this.auth.getAuthSession().user;
    this.apiSrv.get(`/backend/teacher/students/${user?.id}`)
      .subscribe({
        next: (data) => {
          this.students.set(data);
        },
        error: (error) => {
          this.toastSrv.error("Error fetching enrollments", "Error");
          this.isLoading.set(false);
        },
        complete: () => {
          console.log("complete");
          this.isLoading.set(false);
        }
      })
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedResult.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleCloseOffset() {
    this.selectedResult.set(null);
  }
}
