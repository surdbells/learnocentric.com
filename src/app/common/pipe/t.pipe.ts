import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationService } from '../service/translation.service';

@Pipe({
  name: 't',
  standalone: true,
  pure: false,
})
export class TranslatePipe implements PipeTransform {
  private i18n = inject(TranslationService);
  transform(key: string): any {
    return this.i18n.instant(key);
  }
}
