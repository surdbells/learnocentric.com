import {afterNextRender, Component, ElementRef, inject, signal, ViewChild} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';

@Component({
  selector: 'app-analytics',
  standalone: true,
  imports: [PageHeader],
  templateUrl: './analytics.html',
  styleUrl: './analytics.css',
})
export class Analytics {
  @ViewChild('quizChart') quizChart!: ElementRef<HTMLDivElement>;
  @ViewChild('portfolioChart') portfolioChart!: ElementRef<HTMLDivElement>;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any | null>(null);
  private charts: any[] = [];

  constructor() {
    // Charts are browser-only; fetch + render after first client render (SSR-safe).
    afterNextRender(() => this.load());
  }

  private load(): void {
    this.api.get<any>('/backend/analytics/overview').subscribe({
      next: (res) => {
        this.data.set(res);
        this.loading.set(false);
        setTimeout(() => this.renderCharts(res));
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load analytics'); },
    });
  }

  private async renderCharts(d: any): Promise<void> {
    const {default: ApexCharts} = await import('apexcharts');
    this.charts.forEach((c) => c.destroy());
    this.charts = [];

    const brand = '#39c645';

    if (this.quizChart?.nativeElement && d.quiz_by_subject?.length) {
      const chart = new ApexCharts(this.quizChart.nativeElement, {
        chart: {type: 'bar', height: 280, toolbar: {show: false}, fontFamily: 'inherit'},
        series: [
          {name: 'Average %', data: d.quiz_by_subject.map((q: any) => q.average)},
          {name: 'Pass rate %', data: d.quiz_by_subject.map((q: any) => q.pass_rate)},
        ],
        xaxis: {categories: d.quiz_by_subject.map((q: any) => q.subject)},
        yaxis: {max: 100},
        colors: [brand, '#0dcaf0'],
        plotOptions: {bar: {borderRadius: 4, columnWidth: '45%'}},
        dataLabels: {enabled: false},
        legend: {position: 'top'},
        grid: {borderColor: 'rgba(128,128,128,.15)'},
      });
      chart.render();
      this.charts.push(chart);
    }

    if (this.portfolioChart?.nativeElement) {
      const ratings = d.portfolio?.ratings ?? {};
      const labels = Object.keys(ratings).map((k) => k.charAt(0).toUpperCase() + k.slice(1));
      const values = Object.values(ratings) as number[];
      if (values.some((v) => v > 0)) {
        const chart = new ApexCharts(this.portfolioChart.nativeElement, {
          chart: {type: 'donut', height: 280, fontFamily: 'inherit'},
          series: values,
          labels,
          colors: ['#adb5bd', '#0dcaf0', '#0d6efd', brand],
          legend: {position: 'bottom'},
          dataLabels: {enabled: false},
        });
        chart.render();
        this.charts.push(chart);
      }
    }
  }

  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
