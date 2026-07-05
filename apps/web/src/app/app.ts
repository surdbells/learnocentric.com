import {Component, OnInit, Renderer2, Inject, PLATFORM_ID, signal} from '@angular/core';
import { RouterOutlet } from '@angular/router';
import {isPlatformBrowser} from '@angular/common';
import {Preferences} from './common/service/preferences';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.css'
})
export class App implements OnInit{
  protected readonly title = signal('learno-client');

  constructor(
    private prefs: Preferences,
    private renderer: Renderer2,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {}


  ngOnInit() {
    if (isPlatformBrowser(this.platformId)) {
      // Apply the resolved theme ('auto'/'system' -> OS preference).
      this.prefs.theme$.subscribe(theme => {
        this.renderer.setAttribute(document.documentElement, 'data-bs-theme', this.prefs.resolveTheme(theme));
      });
      this.prefs.navigation$.subscribe(nav => {
        this.renderer.setAttribute(document.documentElement, 'data-bs-navigation-position', nav);
      });
      this.prefs.sizing$.subscribe(size => {
        this.renderer.setAttribute(document.documentElement, 'data-bs-sidenav-sizing', size);
      });

      // Re-resolve when the OS theme changes and the user is following the system.
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        const pref = this.prefs.currentTheme;
        if (pref === 'auto' || pref === 'system') {
          this.renderer.setAttribute(document.documentElement, 'data-bs-theme', this.prefs.resolveTheme(pref));
        }
      });
    }
  }
}
