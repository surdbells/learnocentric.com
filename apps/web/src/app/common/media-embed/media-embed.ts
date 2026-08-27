import {Component, computed, inject, input} from '@angular/core';
import {DomSanitizer, SafeResourceUrl} from '@angular/platform-browser';
import {Icon} from '../icon/icon';

type Kind = 'youtube' | 'vimeo' | 'video' | 'audio' | 'pdf' | 'image' | 'link';

/**
 * Renders any lesson media inline: YouTube/Vimeo, direct video/audio files, PDFs
 * and images, detected from the URL, so the Learn experience covers every
 * content type instead of just linking out.
 */
@Component({
  selector: 'app-media-embed',
  standalone: true,
  imports: [Icon],
  templateUrl: './media-embed.html',
  styleUrl: './media-embed.css',
})
export class MediaEmbed {
  url = input.required<string>();
  title = input<string>('Lesson media');

  private readonly sanitizer = inject(DomSanitizer);

  /** File-type media (not a streaming embed) can be saved for offline use. */
  readonly downloadable = computed<boolean>(() => {
    const k = this.kind();
    return k === 'video' || k === 'audio' || k === 'pdf' || k === 'image' || k === 'link';
  });

  /** A sensible filename for the download attribute, taken from the URL. */
  readonly downloadName = computed<string>(() => {
    const u = (this.url() || '').split('?')[0].split('#')[0];
    return u.split('/').pop() || 'download';
  });

  readonly kind = computed<Kind>(() => {
    const u = (this.url() || '').trim();
    if (!u) return 'link';
    if (/(?:youtube\.com\/watch|youtu\.be\/|youtube\.com\/embed)/i.test(u)) return 'youtube';
    if (/vimeo\.com\//i.test(u)) return 'vimeo';
    const ext = (u.split('?')[0].split('#')[0].split('.').pop() || '').toLowerCase();
    if (['mp4', 'webm', 'ogv', 'mov', 'm4v'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'm4a', 'aac', 'oga'].includes(ext)) return 'audio';
    if (ext === 'pdf') return 'pdf';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'].includes(ext)) return 'image';
    return 'link';
  });

  /** Sanitised src for iframe embeds (YouTube / Vimeo / PDF). */
  readonly embedSrc = computed<SafeResourceUrl | null>(() => {
    const u = this.url() || '';
    let src: string | null = null;
    if (this.kind() === 'youtube') {
      const id = this.youtubeId(u);
      src = id ? `https://www.youtube.com/embed/${id}` : null;
    } else if (this.kind() === 'vimeo') {
      const id = (u.match(/vimeo\.com\/(?:video\/)?(\d+)/i) || [])[1];
      src = id ? `https://player.vimeo.com/video/${id}` : null;
    } else if (this.kind() === 'pdf') {
      src = u;
    }
    return src ? this.sanitizer.bypassSecurityTrustResourceUrl(src) : null;
  });

  private youtubeId(u: string): string | null {
    return (u.match(/(?:v=|youtu\.be\/|embed\/)([\w-]{6,})/) || [])[1] ?? null;
  }
}
