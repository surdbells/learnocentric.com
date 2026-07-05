import {afterNextRender, Component, ElementRef, inject, signal, ViewChild} from '@angular/core';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [Icon, RouterLink],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class AdminDashboard {
  @ViewChild('quizChart') quizChart!: ElementRef<HTMLDivElement>;

  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');
  base = signal('/admin/academics');
  mgmt = signal('/admin/management');
  private chart: any = null;

  constructor() {
    const user = this.auth.getAuthSession()?.user;
    this.firstName.set(user?.firstName ?? 'there');
    const academy = user?.role === 'tutor_admin';
    this.base.set(academy ? '/academy/academics' : '/admin/academics');
    this.mgmt.set(academy ? '/academy/management' : '/admin/management');
    afterNextRender(() => this.load());
  }

  private load(): void {
    this.api.get<any>('/backend/dashboard/admin').subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); setTimeout(() => this.renderChart(res)); },
      error: () => this.loading.set(false),
    });
  }

  private async renderChart(d: any): Promise<void> {
    if (!this.quizChart?.nativeElement || !d.quiz_by_subject?.length) return;
    const {default: ApexCharts} = await import('apexcharts');
    this.chart?.destroy();
    this.chart = new ApexCharts(this.quizChart.nativeElement, {
      chart: {type: 'bar', height: 260, toolbar: {show: false}, fontFamily: 'inherit'},
      series: [{name: 'Average', data: d.quiz_by_subject.map((q: any) => q.average)}],
      xaxis: {categories: d.quiz_by_subject.map((q: any) => q.subject)},
      yaxis: {max: 100},
      colors: ['#39c645'],
      plotOptions: {bar: {borderRadius: 6, columnWidth: '45%'}},
      dataLabels: {enabled: true, formatter: (v: number) => v + '%'},
      grid: {borderColor: 'rgba(128,128,128,.15)'},
      tooltip: {y: {formatter: (v: number) => v + '%'}},
    });
    this.chart.render();
  }

  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
