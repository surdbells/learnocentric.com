import { Component } from '@angular/core';
import {Preferences} from '../../service/preferences';
import { TranslatePipe } from '../../pipe/t.pipe';

@Component({
  selector: 'div[preferenceSetting]',
  imports: [TranslatePipe],
  templateUrl: './preference-setting.html',
  styleUrl: './preference-setting.css',
  standalone: true
})
export class PreferenceSetting {

  themes = [
    { name: "Light", icon: "light_mode" },
    { name: "Dark", icon: "dark_mode" },
    // { name: "Auto", icon: "contrast" }
  ];

  languages = [
    { name: 'English', short: 'en' as const },
    { name: 'Français', short: 'fr' as const },
    { name: 'العربية', short: 'ar' as const },
  ];

  sizings = [
    { name: "Base", short: "base", icon: "density_large" },
    { name: "Medium", short: "md", icon: "density_medium" },
    { name: "Small", short: "sm", icon: "density_small" }
  ];
  currentTheme = '';
  currentSizing = '';
  currentLanguage: 'en' | 'fr' | 'ar' = 'en';

  constructor(private preference: Preferences) {
    this.preference.theme$.subscribe( t => this.currentTheme = t);
    this.preference.sizing$.subscribe( s => this.currentSizing = s);
    this.preference.language$.subscribe(l => this.currentLanguage = l as 'en'|'fr'|'ar');
  }

  setTheme(t: string) { this.preference.setTheme(t) }
  setSizing(n: string) { this.preference.setNavSidenavSizing(n) }
  setLanguage(l: 'en'|'fr'|'ar') { this.preference.setLanguage(l) }
}
