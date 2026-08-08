import {
  Component,
  ElementRef,
  Input,
  ViewChild,
  AfterViewInit,
  OnDestroy,
  Inject,
  PLATFORM_ID,
  Output, EventEmitter
} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';

@Component({
  selector: 'app-learno-offset',
  imports: [],
  templateUrl: './learno-offset.html',
  styleUrl: './learno-offset.css'
})
export class LearnoOffset implements AfterViewInit, OnDestroy {
  // Optional custom id to allow multiple offcanvas on a page
  @Input() modalId: string = 'productModal';

  // CSS selector of the element that triggers the preview (used to map/align offset)
  @Input() anchorSelector?: string;

  // Fallback static offset from the top (in pixels) if no anchor is found
  @Input() fixedOffset: number = 0;

  @ViewChild('offcanvasRef', { static: true }) offcanvasRef!: ElementRef<HTMLDivElement>;
  @ViewChild('offsetDismiss', { static: true }) offsetDismiss!: ElementRef<HTMLButtonElement>;

  @Output('closeOffset') closeOffset = new EventEmitter();

  offcanvasStyles: { [k: string]: string } = {};

  private isBrowser: boolean;

  constructor(@Inject(PLATFORM_ID) private platformId: Object) {
    this.isBrowser = isPlatformBrowser(this.platformId);
  }

  // private boundOnResize = () => this.applyDynamicOffset();
  // private boundOnShown = () => this.applyDynamicOffset();
  private boundOnHidden = () => {
    this.resetStyles();
    this.closeOffset.emit();
  };

  ngAfterViewInit(): void {
    if (!this.isBrowser) return;

    const el = this.offcanvasRef?.nativeElement;
    if (!el) return;

    // Listen to Bootstrap offcanvas lifecycle events if present
    // el.addEventListener('shown.bs.offcanvas', this.boundOnShown as EventListener);
    // el.addEventListener('show.bs.offcanvas', this.boundOnShown as EventListener);
    el.addEventListener('hidden.bs.offcanvas', this.boundOnHidden as EventListener);

    // window.addEventListener('resize', this.boundOnResize);
    // window.addEventListener('scroll', this.boundOnResize, { passive: true });
  }

  ngOnDestroy(): void {
    if (!this.isBrowser) return;

    const el = this.offcanvasRef?.nativeElement;
    if (el) {
      // el.removeEventListener('shown.bs.offcanvas', this.boundOnShown as EventListener);
      // el.removeEventListener('show.bs.offcanvas', this.boundOnShown as EventListener);
      el.removeEventListener('hidden.bs.offcanvas', this.boundOnHidden as EventListener);
    }
    // window.removeEventListener('resize', this.boundOnResize);
    // window.removeEventListener('scroll', this.boundOnResize);
  }

  private getAnchorTop(): number {
    if (!this.isBrowser) return this.fixedOffset || 0;
    if (!this.anchorSelector) return this.fixedOffset || 0;
    const anchor = document.querySelector(this.anchorSelector) as HTMLElement | null;
    if (!anchor) return this.fixedOffset || 0;
    const rect = anchor.getBoundingClientRect();
    const top = Math.max(0, Math.round(rect.top));
    return top;
  }

  // private applyDynamicOffset(): void {
  //   const top = this.getAnchorTop();
  //   // Position offcanvas relative to viewport top, and reduce height accordingly
  //   this.offcanvasStyles = {
  //     top: `${top}px`,
  //     height: `calc(100vh - ${top}px)`
  //   };
  // }

  public close(): void {
    if (!this.isBrowser) return;
    try {
      const btn = this.offsetDismiss?.nativeElement as HTMLButtonElement | undefined;
      if (btn) {
        btn.click();
        return;
      }
    } catch {}

    const el = this.offcanvasRef?.nativeElement;
    const anyWin = window as any;
    if (el && anyWin?.bootstrap?.Offcanvas) {
      try {
        const Offcanvas = anyWin.bootstrap.Offcanvas;
        const instance = Offcanvas.getInstance(el) || new Offcanvas(el);
        instance.hide();
      } catch {}
    }

  }

  private resetStyles(): void {
    this.offcanvasStyles = {};
  }
}
