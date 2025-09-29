import {Component, input} from '@angular/core';
import {Router} from "@angular/router";



@Component({
  selector: 'app-page-header',
  standalone: true,
  imports: [],
  templateUrl: './page-header.html',
  styleUrl: './page-header.css'
})
export class PageHeader {

    // title: string = "";
    // subTitle: string = "";
    icon = input<string>("");
    breadcrumbs: any[] = [];
    action = input<string>("");


    constructor(
        private router: Router
    ) {
        this.breadcrumbs = this.router.url.split("/").map((el) => this.toUpperCase(el));
    }


    private toUpperCase(el: string) {
        return el.charAt(0).toUpperCase() + el.slice(1);
    }
}
