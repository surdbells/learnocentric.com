import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {ApiService} from '../../../common/service/api.service';

/** Update this to your real sales / enquiries inbox. */
const CONTACT_EMAIL = 'hello@learnocentric.com';

@Component({
  selector: 'app-public-contact',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './contact.html',
})
export class PublicContact {
  private readonly api = inject(ApiService);
  readonly email = CONTACT_EMAIL;

  name = signal('');
  from = signal('');
  school = signal('');
  role = signal('School administrator');
  message = signal('');
  /** Honeypot: hidden from people, so only bots fill it in. */
  website = signal('');

  busy = signal(false);
  sent = signal<string | null>(null);
  error = signal<string | null>(null);

  /** Send the enquiry to the platform (it is stored and emailed to the support inbox). */
  submit(): void {
    this.error.set(null);
    this.busy.set(true);
    this.api.post<any>('/backend/contact', {
      name: this.name().trim(), email: this.from().trim(), organisation: this.school().trim(),
      role: this.role(), message: this.message().trim(), website: this.website(),
    }).subscribe({
      next: (r) => { this.busy.set(false); this.sent.set(r?.message ?? 'Thank you. We have your message and will be in touch.'); },
      error: (e) => {
        this.busy.set(false);
        this.error.set(e?.error?.error || 'We could not send your message just now.');
      },
    });
  }

  /** Fallback if the form can't be sent: open the visitor's own email app instead. */
  mailtoHref(): string {
    const subject = `Demo request from ${this.school() || this.name() || 'a school'}`;
    const body = [
      `Name: ${this.name()}`,
      `Email: ${this.from()}`,
      `School / organisation: ${this.school()}`,
      `Role: ${this.role()}`,
      '',
      this.message(),
    ].join('\n');
    return `mailto:${CONTACT_EMAIL}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  }
}
