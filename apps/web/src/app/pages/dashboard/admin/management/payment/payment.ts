import {Component, OnInit, ViewChild, computed, signal, ElementRef} from '@angular/core';
import {CurrencyPipe, DatePipe, NgIf} from '@angular/common';
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';

@Component({
  selector: 'app-payment',
  imports: [
    CurrencyPipe,
    DataTable,
    DataTableNumbering,
    TableSearch,
    LearnoButton,
    LearnoModal,
    LearnoOffset,
    NgIf,
    PageHeader,
    DatePipe,
    SkeletonLoader
  ],
  templateUrl: './payment.html',
  styleUrl: './payment.css'
})
export class Payment implements OnInit {
  isLoading = signal(false);

  // Enrolled students
  enrollments = signal<any[]>([]);
  searchTerm = signal<string>('');

  // Selected student and their payments
  selectedStudent = signal<any | null>(null);
  payments = signal<any[]>([]);
  selectedPayment = signal<any | null>(null);
  anchorSelector = signal<string>('');

  // Modal form field
  transactionId = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;


  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
  ) {}

  ngOnInit(): void {
    this.loadEnrollments();
  }

  filterEnrollments = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if (!term) return this.enrollments();
    return this.enrollments().filter((s: any) => {
      const email = (s.email || '').toString().toLowerCase();
      const first = (s.first_name || '').toString().toLowerCase();
      const last = (s.last_name || '').toString().toLowerCase();
      const phone = (s.phone || '').toString().toLowerCase();
      return email.includes(term) || first.includes(term) || last.includes(term) || phone.includes(term);
    });
  });

  private loadEnrollments() {
    this.isLoading.set(true);
    this.apiSrv.get<any[]>("/backend/school/enrollments")
      .subscribe({
        next: (data) => {
          this.enrollments.set(data || []);
        },
        error: () => {
          this.toastSrv.error("Error fetching enrollments", "Error");
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
  }

  // When a student row is previewed (clicked)
  onPreviewStudent(evt: { row: any; anchorSelector: string }) {
    this.selectedStudent.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
    this.loadPaymentsForStudent(evt.row?.student_id || evt.row?.id);
  }

  private loadPaymentsForStudent(studentId: number) {
    if (!studentId) return;
    this.isLoading.set(true);
    // Assumed endpoint supports filtering by student_id
    this.apiSrv.get<any[]>(`/backend/payments/list?userId=${studentId}`)
      .subscribe({
        next: (data) => {
          this.payments.set(data || []);
        },
        error: () => {
          this.toastSrv.error("Error fetching payments", "Error");
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }

  // When a payment is clicked to update transaction
  onSelectPayment(pay: any) {
    this.selectedPayment.set(pay);
    this.transactionId.set(pay?.transaction_id || '');
  }

  submitTransactionUpdate() {
    const pay = this.selectedPayment();
    if (!pay) return;
    const paymentId = pay.id;
    const txn = this.transactionId().trim();
    if (!txn) {
      this.toastSrv.error('Please enter a transaction ID', 'Validation');
      return;
    }
    this.isLoading.set(true);
    // Assumed endpoint for updating transaction on a payment
    this.apiSrv.post(`/backend/payments/confirm`, { transactionId: txn, paymentId: paymentId })
      .subscribe({
        next: () => {
          this.toastSrv.success('Transaction updated');
          // refresh payments list
          const sid = this.selectedStudent()?.student_id || this.selectedStudent()?.id;
          this.loadPaymentsForStudent(sid);
        },
        error: () => {
          this.toastSrv.error('Failed to update transaction', 'Error');
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
          this.autoCloseBtn.nativeElement.click();
        }
      });
  }

  handleCloseOffset() {
    this.selectedStudent.set(null);
    this.payments.set([]);
    this.anchorSelector.set('');
  }
}
