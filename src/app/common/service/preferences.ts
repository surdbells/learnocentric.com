import {inject, Injectable, PLATFORM_ID} from '@angular/core';
import {BehaviorSubject} from 'rxjs';
import {isPlatformBrowser} from '@angular/common';

@Injectable({
  providedIn: 'root'
})
export class Preferences {

  private plaformId = inject(PLATFORM_ID);
  private themeSubject = new BehaviorSubject<string>(this.getStoredTheme() || this.getPreferredTheme() || 'dark');
  private navigationSubject = new BehaviorSubject<string>(this.getStoredNavigationPosition() || 'sidenav');
  private sizingSubject = new BehaviorSubject<string>(this.getStoredSidenavSizing() || 'base');
  private languageSubject = new BehaviorSubject<string>(this.getStoredLanguage() || 'en');

  theme$ = this.themeSubject.asObservable();
  navigation$ = this.navigationSubject.asObservable();
  sizing$ = this.sizingSubject.asObservable();
  language$ = this.languageSubject.asObservable();

  constructor() {
    // apply initial theme, nav, sizing attributes
    if (isPlatformBrowser(this.plaformId)) {
      const lang = this.languageSubject.value;
      this.applyLanguageAttributes(lang);
    }
  }

  setTheme(theme: string): void {
    localStorage.setItem('theme', theme);
    this.themeSubject.next(theme);
    if (theme === 'auto') {
      document.documentElement.setAttribute('data-bs-theme', window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    } else {
      document.documentElement.setAttribute('data-bs-theme', theme);
    }
  }

  setNavigationPosition(navigationPosition: string): void {
    localStorage.setItem('navigationPosition', navigationPosition);
    this.navigationSubject.next(navigationPosition);
    document.documentElement.setAttribute('data-bs-navigation-position', navigationPosition);
  }

  setNavSidenavSizing(sidenavSizing: string): void {
    localStorage.setItem('sidenavSizing', sidenavSizing);
    this.sizingSubject.next(sidenavSizing);
    document.documentElement.setAttribute('data-bs-sidenav-sizing', sidenavSizing);
  }

  setLanguage(lang: 'en' | 'fr' | 'ar'): void {
    localStorage.setItem('language', lang);
    this.languageSubject.next(lang);
    this.applyLanguageAttributes(lang);
  }

  private applyLanguageAttributes(lang: string) {
    if (!isPlatformBrowser(this.plaformId)) return;
    const isArabic = lang === 'ar';
    const html = document.documentElement;
    html.setAttribute('lang', lang);
    html.setAttribute('dir', isArabic ? 'rtl' : 'ltr');
    // add a data attribute for CSS hooks if needed
    html.setAttribute('data-app-lang', lang);
    if (isArabic) {
      html.classList.add('rtl');
    } else {
      html.classList.remove('rtl');
    }
  }

  private getStoredTheme() {
    if(isPlatformBrowser(this.plaformId)) return localStorage.getItem('theme');
    return null
  }
  private getStoredNavigationPosition() {
    if(isPlatformBrowser(this.plaformId)) return localStorage.getItem('navigationPosition');
    return null
  }
  private getStoredSidenavSizing() {
    if (isPlatformBrowser(this.plaformId)) return localStorage.getItem('sidenavSizing');
    return null
  }
  private getPreferredTheme() {
    if(isPlatformBrowser(this.plaformId)) return localStorage.getItem('preferredTheme');
    return null
  }
  private getStoredLanguage() {
    if (isPlatformBrowser(this.plaformId)) return localStorage.getItem('language');
    return null;
  }
}
