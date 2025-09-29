import { Injectable, inject } from '@angular/core';
import { BehaviorSubject, map } from 'rxjs';
import { Preferences } from './preferences';

export type Lang = 'en' | 'fr' | 'ar';

const DICTS: Record<Lang, Record<string, string>> = {
  en: {
    settings: 'Settings',
    color_mode: 'Color mode',
    language: 'Language',
    sidenav_sizing: 'Sidenav sizing',
    search: 'Search',
    notifications: 'Notifications',
    mark_all_as_read: 'Mark all as read',
    contact_us: 'Contact us',
    go_to_product_page: 'Go to product page',
    update_now: 'Update now',
    dismiss: 'Dismiss',
  },
  fr: {
    settings: 'Paramètres',
    color_mode: 'Mode de couleur',
    language: 'Langue',
    sidenav_sizing: 'Taille de la barre latérale',
    search: 'Rechercher',
    notifications: 'Notifications',
    mark_all_as_read: 'Tout marquer comme lu',
    contact_us: 'Nous contacter',
    go_to_product_page: 'Aller à la page du produit',
    update_now: 'Mettre à jour maintenant',
    dismiss: 'Ignorer',
  },
  ar: {
    settings: 'الإعدادات',
    color_mode: 'وضع الألوان',
    language: 'اللغة',
    sidenav_sizing: 'حجم الشريط الجانبي',
    search: 'بحث',
    notifications: 'الإشعارات',
    mark_all_as_read: 'وضع الكل كمقروء',
    contact_us: 'اتصل بنا',
    go_to_product_page: 'الذهاب إلى صفحة المنتج',
    update_now: 'حدّث الآن',
    dismiss: 'تجاهل',
  },
};

@Injectable({ providedIn: 'root' })
export class TranslationService {
  private prefs = inject(Preferences);
  private lang$ = new BehaviorSubject<Lang>('en');

  constructor() {
    // sync with preferences language
    this.prefs.language$.subscribe((l) => this.lang$.next((l as Lang) || 'en'));
  }

  instant(key: string): string {
    const lang = this.lang$.value;
    return DICTS[lang][key] ?? DICTS['en'][key] ?? key;
  }

  translate$(key: string) {
    return this.lang$.pipe(map((lang) => DICTS[lang][key] ?? DICTS['en'][key] ?? key));
  }
}
