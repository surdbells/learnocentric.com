import {Component, signal} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {PaymentService} from "./payment-service";
import {IOrder} from "./model/i-order";
import {PdfService} from "../../../../../common/service/pdf-service";

@Component({
  selector: 'app-payment',
    imports: [
        DataTable,
        TableSearch
    ],
  templateUrl: './payment.html',
  styleUrl: './payment.css'
})
export class Payment {
    

    orders = signal<IOrder[]>([])
    constructor(
        private readonly paymentService: PaymentService,
        private readonly pdfService: PdfService
        ) {
        this.orders.set(this.paymentService.getOrders());
    }


    handlePdfDownload() {
        this.pdfService.generateHtmlPdf("invoice.pdf", 'invoice');
    }
}
