import {Component, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';

/** Update this to your real sales / enquiries inbox. */
const CONTACT_EMAIL = 'hello@learnocentric.com';

@Component({
  selector: 'app-public-contact',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './contact.html',
})
export class PublicContact {
  readonly email = CONTACT_EMAIL;

  name = signal('');
  from = signal('');
  school = signal('');
  role = signal('School administrator');
  message = signal('');

  /** Compose a pre-filled email to our inbox (no backend needed). */
  submit(): void {
    const subject = `Demo request from ${this.school() || this.name() || 'a school'}`;
    const body = [
      `Name: ${this.name()}`,
      `Email: ${this.from()}`,
      `School / organisation: ${this.school()}`,
      `Role: ${this.role()}`,
      '',
      this.message(),
    ].join('\n');
    window.location.href = `mailto:${CONTACT_EMAIL}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  }
}
