import { inject, Injectable } from '@angular/core';
import { PdfService } from './pdf-service';

/** Worksheet fields needed to render the branded worksheet template. */
export interface TemplateWorksheet {
  title?: string;
  topic?: string;
  subject?: string;
  class_label?: string | null;
  track?: string;
  worked_example?: string | null;
  instructions?: string | null;
}

/** One question row (learner shape) used to lay out the worksheet sections. */
export interface TemplateQuestion {
  section_label?: string | null;
  section_position?: number;
  position?: number;
  prompt: string;
  type?: string;
  marks?: number;
}

export interface PortfolioMissionItem { label: string; detail?: string; }
export interface PortfolioRubricRow { criterion: string; standard?: string; }

/** Portfolio-task fields needed to render the branded portfolio template. */
export interface TemplatePortfolioTask {
  title?: string;
  subject?: string;
  class_label?: string | null;
  portfolio_evidence_expected?: string | null;
  portfolio_task?: {
    aim?: string;
    sub_aim?: string;
    mission?: PortfolioMissionItem[];
    evidence?: string[];
    rubric?: PortfolioRubricRow[];
  } | null;
}

/**
 * Generates the branded, downloadable response templates learners complete
 * offline and re-upload (template-upload worksheets and portfolio tasks).
 * Builds the document markup from the task's own content and renders it to a
 * paginated A4 PDF via {@link PdfService}, so the output matches the
 * Learn-O'Centric worksheet / portfolio layouts with no server dependency.
 */
@Injectable({ providedIn: 'root' })
export class AssessmentTemplateService {
  private readonly pdf = inject(PdfService);

  private readonly LOGO = 'images/logo/logoblack.png';

  // Brand palette (kept inline on the markup; html2canvas reads computed styles only).
  private readonly WS_GREEN = '#1b5e20';
  private readonly WS_BORDER = '#1e8a2e';
  private readonly TEAL = '#15806e';
  private readonly NAVY = '#1f3a56';
  private readonly GOLD = '#e0a53f';
  private readonly LINE = '#cfd8d3';
  private readonly SOFT = '#eef3f1';

  /** Download the branded worksheet template for a template-upload worksheet. */
  async downloadWorksheetTemplate(ws: TemplateWorksheet, questions: TemplateQuestion[] = []): Promise<void> {
    const html = this.buildWorksheetHtml(ws, questions);
    await this.pdf.generateHtmlStringPdf(`${this.slug(ws.title || ws.topic || 'worksheet')}-worksheet.pdf`, html);
  }

  /** Download the branded portfolio-task template. */
  async downloadPortfolioTemplate(task: TemplatePortfolioTask): Promise<void> {
    const html = this.buildPortfolioHtml(task);
    const name = task.portfolio_task?.aim || task.title || 'portfolio-task';
    await this.pdf.generateHtmlStringPdf(`${this.slug(name)}-portfolio-task.pdf`, html);
  }

  // --- Worksheet markup -----------------------------------------------------

  private buildWorksheetHtml(ws: TemplateWorksheet, questions: TemplateQuestion[]): string {
    const topic = this.esc(ws.topic || ws.title || 'Worksheet');
    const subTopic = this.esc(ws.title && ws.topic ? ws.title : '');
    const meta = this.esc([ws.class_label || this.trackLabel(ws.track), ws.subject, ws.topic].filter(Boolean).join('  |  '));

    const header = `
      <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
        <img src="${this.LOGO}" alt="Learn-O'Centric" style="height:34px;" />
      </div>
      <div style="background:${this.WS_GREEN};color:#fff;padding:18px 22px;">
        <div style="font-size:12px;letter-spacing:.14em;font-weight:700;opacity:.92;">WORKSHEET</div>
        <div style="font-size:30px;font-weight:800;line-height:1.1;margin:4px 0;">${topic}</div>
        ${subTopic ? `<div style="font-size:13px;opacity:.85;">${subTopic}</div>` : ''}
        <div style="text-align:right;font-size:12px;font-weight:600;margin-top:6px;">${meta}</div>
      </div>
      <div style="display:flex;justify-content:space-between;margin:18px 2px;font-size:13px;color:#222;">
        <div>Name: ____________________________________</div>
        <div>Date: ____________________________</div>
      </div>`;

    const worked = ws.worked_example?.trim()
      ? `<div style="border:1.5px solid ${this.WS_BORDER};padding:14px 16px;margin:0 0 18px;">
           <div style="color:${this.TEAL};font-weight:700;text-decoration:underline;font-size:13px;">WORKED EXAMPLE</div>
           <div style="font-size:13px;margin-top:8px;white-space:pre-wrap;color:#222;">${this.esc(ws.worked_example!)}</div>
         </div>`
      : '';

    let body = '';
    if (questions.length) {
      for (const sec of this.groupSections(questions)) {
        body += `<div style="color:#16324f;font-weight:700;font-size:15px;margin:16px 0 8px;">${this.esc(sec.label)}</div>`;
        sec.items.forEach((q, i) => {
          body += `
            <div style="margin:0 0 14px;">
              <div style="font-size:13px;margin-bottom:6px;color:#222;">${i + 1}. ${this.esc(q.prompt)}</div>
              ${this.ruled(3)}
            </div>`;
        });
        body += `<div style="background:${this.SOFT};padding:6px 10px;font-size:12px;font-weight:600;color:#222;margin-bottom:6px;">Final answer:</div>`;
      }
    } else {
      if (ws.instructions?.trim()) {
        body += `<div style="font-size:13px;margin:0 0 10px;white-space:pre-wrap;color:#222;">${this.esc(ws.instructions!)}</div>`;
      }
      body += `<div style="color:#16324f;font-weight:700;font-size:15px;margin:10px 0 8px;">Your working</div>${this.ruled(10)}`;
    }

    const confidence = `
      <div style="margin-top:22px;font-size:13px;color:${this.TEAL};font-weight:600;">
        Confidence: &nbsp;&nbsp; &#9633; I need help &nbsp;&nbsp;&nbsp; &#9633; I am getting it &nbsp;&nbsp;&nbsp; &#9633; I can explain it
      </div>`;

    return this.wrap(header + worked + body + confidence);
  }

  // --- Portfolio markup -----------------------------------------------------

  private buildPortfolioHtml(task: TemplatePortfolioTask): string {
    const pt = task.portfolio_task || {};
    const aim = this.esc(pt.aim || task.title || 'Portfolio Task');
    const subAim = this.esc(pt.sub_aim || '');
    const meta = this.esc([task.class_label, task.subject, task.title].filter(Boolean).join('  |  '));
    const brief = this.esc(task.portfolio_evidence_expected || '');

    const mission = (pt.mission?.length ? pt.mission : [
      { label: '1 MODEL' }, { label: '2 SOLVE' }, { label: '3 DESIGN' }, { label: '4 EXPLAIN' },
    ]);
    const evidence = (pt.evidence?.length ? pt.evidence : ['A. [Caption]', 'B. [Caption]', 'C. [Caption]']);
    const rubric = (pt.rubric?.length ? pt.rubric : [
      { criterion: '1. Knowledge' }, { criterion: '2. Skill' }, { criterion: '3. Real-life application' },
      { criterion: '4. Communication' }, { criterion: '5. Collaboration' },
    ]);

    const header = `
      <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
        <img src="${this.LOGO}" alt="Learn-O'Centric" style="height:34px;" />
      </div>
      <div style="background:${this.NAVY};color:#fff;padding:16px 20px;">
        <div style="color:${this.GOLD};font-weight:700;letter-spacing:.12em;font-size:12px;">PORTFOLIO TASK</div>
        <div style="font-size:26px;font-weight:800;margin:4px 0;">${aim}</div>
        ${subAim ? `<div style="font-size:13px;opacity:.85;">${subAim}</div>` : ''}
        <div style="text-align:right;font-size:12px;font-weight:600;margin-top:4px;">${meta}</div>
      </div>
      <div style="border:1px solid #d7e0da;border-top:none;padding:12px 16px;margin-bottom:18px;">
        <div style="color:${this.TEAL};font-weight:700;font-size:13px;">THE BRIEF</div>
        <div style="font-size:13px;margin-top:4px;white-space:pre-wrap;color:#222;">${brief || '&nbsp;'}</div>
      </div>`;

    // Mission: two-column grid.
    let missionRows = '';
    for (let i = 0; i < mission.length; i += 2) {
      missionRows += `<tr>${this.missionCell(mission[i])}${mission[i + 1] ? this.missionCell(mission[i + 1]) : '<td style="border:1px solid #d7e0da;"></td>'}</tr>`;
    }
    const missionBlock = `
      <div style="font-weight:700;font-size:15px;margin:6px 0 8px;color:#222;">Your mission</div>
      <table style="width:100%;border-collapse:collapse;">${missionRows}</table>`;

    const evidenceCells = evidence
      .map(e => `<td style="border:1px solid #d7e0da;padding:14px 10px;text-align:center;font-size:12px;color:#222;">&#9633;&nbsp; ${this.esc(e)}</td>`)
      .join('');
    const evidenceBlock = `
      <div style="font-weight:700;font-size:15px;margin:18px 0 8px;color:#222;">Evidence to submit</div>
      <table style="width:100%;border-collapse:collapse;"><tr>${evidenceCells}</tr></table>`;

    const workArea = `
      <div style="font-weight:700;font-size:15px;margin:18px 0 6px;color:#222;">Work area</div>
      <div style="background:#eef6f4;color:${this.TEAL};text-align:center;font-size:12px;padding:6px;">[INSERT YOUR TIMELINE, TABLE, PHOTO OR POSTER BELOW]</div>
      <div style="height:170px;border:1px solid #d7e0da;border-top:none;"></div>`;

    const reflection = `
      <div style="font-weight:700;font-size:15px;margin:18px 0 6px;color:#222;">Reflection</div>
      <div style="font-size:13px;color:#222;margin-bottom:6px;">The strongest part of my work is ...</div>
      ${this.ruled(2)}`;

    const rubricRows = rubric.map(r => `
      <tr>
        <td style="border:1px solid #d7e0da;padding:10px;color:${this.TEAL};font-weight:700;font-size:12px;vertical-align:top;">${this.esc(r.criterion)}</td>
        <td style="border:1px solid #d7e0da;padding:10px;font-size:12px;color:#222;vertical-align:top;">${this.esc(r.standard || '')}</td>
        <td style="border:1px solid #d7e0da;padding:10px;font-size:12px;color:#222;vertical-align:top;">Evidence: ______________________<br/>Next: ______________________</td>
      </tr>`).join('');
    const rubricBlock = `
      <div style="font-weight:700;font-size:15px;margin:18px 0 8px;color:#222;">Portfolio Rubric</div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="background:${this.NAVY};color:#fff;font-size:12px;">
            <th style="border:1px solid ${this.NAVY};padding:9px;text-align:left;">Criterion</th>
            <th style="border:1px solid ${this.NAVY};padding:9px;text-align:left;">Expected standard</th>
            <th style="border:1px solid ${this.NAVY};padding:9px;text-align:left;">Evidence / next step</th>
          </tr>
        </thead>
        <tbody>${rubricRows}</tbody>
      </table>`;

    return this.wrap(header + missionBlock + evidenceBlock + workArea + reflection + rubricBlock);
  }

  private missionCell(m: PortfolioMissionItem): string {
    return `<td style="border:1px solid #d7e0da;padding:10px 12px;width:50%;vertical-align:top;">
      <div style="color:${this.TEAL};font-weight:700;font-size:13px;">${this.esc(m.label)}</div>
      <div style="font-size:12px;color:#222;margin-top:3px;white-space:pre-wrap;">${this.esc(m.detail || '...')}</div>
    </td>`;
  }

  // --- Helpers --------------------------------------------------------------

  private wrap(inner: string): string {
    return `<div style="font-family:'Segoe UI',Arial,sans-serif;color:#222;background:#fff;padding:28px 30px;box-sizing:border-box;">${inner}</div>`;
  }

  private ruled(n: number): string {
    return Array.from({ length: n })
      .map(() => `<div style="border-bottom:1px solid ${this.LINE};height:24px;"></div>`)
      .join('');
  }

  private groupSections(questions: TemplateQuestion[]): { label: string; items: TemplateQuestion[] }[] {
    const map = new Map<string, { position: number; label: string; items: TemplateQuestion[] }>();
    questions.forEach((q, idx) => {
      const label = (q.section_label && q.section_label.trim()) || 'Questions';
      const key = `${q.section_position ?? 0}|${label}`;
      if (!map.has(key)) map.set(key, { position: q.section_position ?? idx, label, items: [] });
      map.get(key)!.items.push(q);
    });
    return Array.from(map.values())
      .sort((a, b) => a.position - b.position)
      .map(s => ({
        label: s.label,
        items: s.items.sort((a, b) => (a.position ?? 0) - (b.position ?? 0)),
      }));
  }

  private trackLabel(track?: string): string {
    if (track === 'competency') return 'Competency';
    if (track === 'academic') return 'Academic';
    return '';
  }

  private slug(s: string): string {
    return (s || 'document').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'document';
  }

  private esc(s: string): string {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
}
