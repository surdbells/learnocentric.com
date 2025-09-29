import {Inject, Injectable, PLATFORM_ID} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {Preferences} from './preferences';

@Injectable({
  providedIn: 'root'
})
export class CustomizerSettingsService {

  private currentTheme: string = 'light';

  constructor(
    private readonly preferences: Preferences,
    @Inject(PLATFORM_ID) private readonly platformId: Object
  ) {
    // Keep a local copy of the current theme for quick boolean checks
    this.preferences.theme$.subscribe(theme => {
      this.currentTheme = theme;
    });
  }

  isDark(): boolean {
    if (!isPlatformBrowser(this.platformId)) {
      return this.currentTheme === 'dark';
    }
    if (this.currentTheme === 'auto') {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    return this.currentTheme === 'dark';
  }

  isRTLEnabled(): boolean {
    if (!isPlatformBrowser(this.platformId)) {
      return false;
    }
    const dir = document.documentElement.getAttribute('dir');
    return dir === 'rtl';
  }
}


