import {Component, computed, input} from '@angular/core';
import {
  LucideAngularModule,
  Activity, ArrowLeft, ArrowRight, Award, BadgeCheck, Bell, Book, BookMarked, BookOpen,
  Building, Building2, Calendar, Check, CheckCheck, ChevronDown, ChevronLeft, ChevronRight,
  ChartLine, Circle, CircleCheck, CirclePlay, CircleX, ClipboardCheck, ClipboardList, ClipboardPen, Clock,
  CloudUpload, CreditCard, DoorOpen, Eye, FilePen, FileText, FileUp, Folder, FolderOpen,
  AlarmClock, Download, GraduationCap, Headset, House, Inbox, Layers, LayoutDashboard, Library,
  LifeBuoy, ListFilter, ListChecks, LogOut, Megaphone, Menu, MessageSquare, MessagesSquare, Monitor,
  Image, Moon, Music, Newspaper, PanelLeftClose, PartyPopper, Pencil, Play, Plus, Pointer, Receipt,
  Search, Settings, Shield, ShieldCheck, Smile, SquarePen, Star, Sun, Tag, Trash2, TrendingUp,
  User, UserCog, UserPlus, Users, Video, Wallet, X,
} from 'lucide-angular';

/**
 * Icon wrapper backed by Lucide. Call sites keep passing the old Material-symbol
 * names (many are produced dynamically in TS), and this component maps them to
 * the equivalent Lucide glyph — so migrating icons didn't require touching that
 * logic. Unknown names fall back to a neutral dot.
 */
const MAP: Record<string, any> = {
  // actions / chrome
  add: Plus, close: X, check: Check, done_all: CheckCheck, delete: Trash2, edit: Pencil,
  search: Search, settings: Settings, filter_list: ListFilter, menu: Menu, menu_open: PanelLeftClose,
  visibility: Eye, logout: LogOut, notifications: Bell, expand_more: ChevronDown,
  chevron_left: ChevronLeft, chevron_right: ChevronRight, arrow_back: ArrowLeft, arrow_forward: ArrowRight,
  circle: Circle, check_circle: CircleCheck, cancel: CircleX, verified: BadgeCheck,
  light_mode: Sun, dark_mode: Moon, cloud_upload: CloudUpload, upload_file: FileUp,
  play_arrow: Play, play_circle: CirclePlay, smart_display: Play, touch_app: Pointer,
  // people / org
  group: Users, supervisor_account: UserCog, person_add: UserPlus, face: Smile, person: User, account_circle: User,
  apartment: Building2, domain: Building2, tenancy: Building, home_and_garden: House, meeting_room: DoorOpen,
  // learning / content
  school: GraduationCap, dashboard: LayoutDashboard, subject: BookMarked, book: Book,
  menu_book: BookOpen, local_library: BookOpen, library_books: Library, folder_special: Star,
  description: FileText, news: Newspaper, folder: Folder, folder_open: FolderOpen,
  assignment: ClipboardList, assignment_turned_in: ClipboardCheck, quiz: ListChecks, grading: ClipboardPen,
  workspace_premium: Award, interactive: Pointer, document: FileText, video: Video, edit_note: FilePen,
  // comms / feedback
  forum: MessagesSquare, chat: MessageSquare, inbox: Inbox, campaign: Megaphone, celebration: PartyPopper,
  support: LifeBuoy, support_agent: Headset,
  // media / scheduling
  video_camera_front: Video, videocam: Video, schedule: Clock, calendar_month: Calendar,
  // finance / analytics
  payments: Wallet, credit_card: CreditCard, receipt: Receipt, sell: Tag,
  insights: ChartLine, monitoring: Activity, trending_up: TrendingUp,
  // misc
  shield: Shield, layers: Layers, monitor: Monitor, download: Download, groups: Users,
  alarm: AlarmClock, edit_square: SquarePen, verified_user: ShieldCheck,
  circle_check: CircleCheck, group_add: UserPlus, audiotrack: Music, image: Image,
};

@Component({
  selector: 'app-icon',
  standalone: true,
  imports: [LucideAngularModule],
  template: `<lucide-icon [img]="glyph()" [size]="size()" [strokeWidth]="1.9" class="app-icon" />`,
  styles: [`:host{display:inline-flex;line-height:0}.app-icon{vertical-align:middle}`],
})
export class Icon {
  /** Material-symbol name (kept for compatibility) or any mapped key. */
  name = input<string | null | undefined>('');
  size = input<number>(20);

  readonly glyph = computed(() => MAP[this.name() ?? ''] ?? Circle);
}
