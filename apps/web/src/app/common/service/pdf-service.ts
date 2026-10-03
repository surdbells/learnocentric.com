import { Injectable } from '@angular/core';
// @ts-ignore - jsPDF's SSR/node build ships no adjacent typings (TS7016)
import jsPDF  from "jspdf";
import html2canvas from "html2canvas";

@Injectable({
  providedIn: 'root'
})
export class PdfService {

    generateTextPdf(fileName: string, textContent: string) {
        const pdf = new jsPDF();
        const margin = 10;
        const pageHeight = pdf.internal.pageSize.height;

        // Split text into pages if it's long
        const lines = pdf.splitTextToSize(textContent, 180);
        let cursorY = margin;

        lines.forEach((line: string) => {
            if (cursorY > pageHeight - margin) {
                pdf.addPage();
                cursorY = margin;
            }
            pdf.text(line, margin, cursorY);
            cursorY += 7; // line spacing
        });

        pdf.save(fileName);
    }

    async generateHtmlPdf(fileName: string, elementId: string) {
        const element = document.getElementById(elementId);
        if (!element) {
            console.error(`Element with id '${elementId}' not found.`);
            return;
        }

// Clone the element to avoid affecting the real DOM
        const clone = element.cloneNode(true) as HTMLElement;

        // Force Bootstrap light mode on the clone
        clone.setAttribute('data-bs-theme', 'light');

        // Optional: enforce white background and black text in case theme variables fail
        clone.style.backgroundColor = '#ffffff';
        clone.style.color = '#000000';

        // Render off-screen
        const container = document.createElement('div');
        container.style.position = 'fixed';
        container.style.left = '-9999px'; // hide off-screen
        container.appendChild(clone);
        document.body.appendChild(container);

        // Capture the clone with html2canvas
        const canvas = await html2canvas(clone, {
            scale: 1,
            backgroundColor: '#ffffff' // ensures a white PDF background
        });
        // Cleanup
        document.body.removeChild(container);

        this.canvasToA4Pdf(canvas, fileName);
    }

    /**
     * Render a self-contained HTML string to a paginated A4 PDF and download it.
     * The markup is laid out off-screen at A4 width (96dpi), captured with
     * html2canvas, then split across pages. Used to generate branded documents
     * (worksheet / portfolio task templates) without embedding hidden markup in
     * every component. Styling must be inline on the markup (html2canvas reads
     * computed styles only).
     */
    async generateHtmlStringPdf(fileName: string, html: string, widthPx = 794): Promise<void> {
        const container = document.createElement('div');
        container.setAttribute('data-bs-theme', 'light');
        container.style.position = 'fixed';
        container.style.left = '-9999px';
        container.style.top = '0';
        container.style.width = `${widthPx}px`; // ~210mm at 96dpi
        container.style.backgroundColor = '#ffffff';
        container.style.color = '#000000';
        container.innerHTML = html;
        document.body.appendChild(container);

        try {
            await this.waitForImages(container);
            const canvas = await html2canvas(container, {
                scale: 2, // crisp text / lines
                backgroundColor: '#ffffff',
                useCORS: true,
                windowWidth: widthPx,
            });
            this.canvasToA4Pdf(canvas, fileName);
        } finally {
            document.body.removeChild(container);
        }
    }

    /** Resolve once every <img> inside the root has loaded (or failed), so the capture isn't blank. */
    private waitForImages(root: HTMLElement): Promise<unknown> {
        const imgs = Array.from(root.querySelectorAll('img'));
        return Promise.all(
            imgs.map(img =>
                img.complete && img.naturalWidth > 0
                    ? Promise.resolve()
                    : new Promise<void>(resolve => {
                          img.addEventListener('load', () => resolve(), { once: true });
                          img.addEventListener('error', () => resolve(), { once: true });
                      })
            )
        );
    }

    /** Split a tall canvas across A4 pages and save it. */
    private canvasToA4Pdf(canvas: HTMLCanvasElement, fileName: string): void {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jsPDF('p', 'mm', 'a4');
        const imgWidth = 210; // A4 width in mm
        const pageHeight = 297; // A4 height in mm
        // Scale the captured canvas to the page width and derive the true height so
        // long documents keep their full aspect ratio instead of being clipped.
        const imgHeight = (canvas.height * imgWidth) / canvas.width;

        let heightLeft = imgHeight;
        let position = 0;

        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;

        // Additional pages: shift the same tall image up by one page each time.
        while (heightLeft > 0) {
            position -= pageHeight;
            pdf.addPage();
            pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;
        }

        pdf.save(fileName);
    }
}
