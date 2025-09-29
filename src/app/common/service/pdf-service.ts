import { Injectable } from '@angular/core';
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

        const imgData = canvas.toDataURL('image/png');

        const pdf = new jsPDF('p', 'mm', 'a4');
        const imgWidth = 210; // A4 width in mm
        const pageHeight = 297; // A4 height in mm
        const imgHeight = 0.8 * 297; //(canvas.height * imgWidth) / canvas.width;

        let heightLeft = imgHeight;
        let position = 0;

        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;

        // Handle multipage
        
        // while (heightLeft > 0) {
        //     position = - (imgHeight - heightLeft); // ✅ correct positioning
        //     // pdf.addPage();
        //     pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
        //     heightLeft -= pageHeight;
        // }s
        pdf.save(fileName);
    }
}
