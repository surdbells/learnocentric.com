import { Injectable } from '@angular/core';
import {IOrder} from "./model/i-order";

@Injectable({
  providedIn: 'root'
})
export class PaymentService {
     private readonly orders: IOrder[] = [
        {
            id: 3456,
            product: "Apple MacBook Pro",
            date: "2021-08-12",
            status: "Completed",
            price: "$2,499",
        },
        {
            id: 3455,
            product: "Apple iPhone 12 Pro",
            date: "2021-08-11",
            status: "Pending",
            price: "$1,099",
        },
        {
            id: 3454,
            product: "Apple AirPods Pro",
            date: "2021-08-10",
            status: "Pending",
            price: "$249",
        },
        {
            id: 3453,
            product: "Apple Watch Series 6",
            date: "2021-08-09",
            status: "Completed",
            price: "$399",
        },
        {
            id: 3452,
            product: "Apple iPad Pro",
            date: "2021-08-08",
            status: "Cancelled",
            price: "$799",
        },
        {
            id: 3451,
            product: "Apple MacBook Air",
            date: "2021-08-07",
            status: "Completed",
            price: "$999",
        },
        {
            id: 3450,
            product: "Apple HomePod Mini",
            date: "2021-08-06",
            status: "Cancelled",
            price: "$99",
        },
        {
            id: 3449,
            product: "Apple Magic Keyboard",
            date: "2021-08-05",
            status: "Pending",
            price: "$299",
        },
        {
            id: 3448,
            product: "Apple Magic Mouse",
            date: "2021-08-04",
            status: "Completed",
            price: "$99",
        },
    ];
     
     getOrders(): IOrder[] {
         return this.orders;
     }
}
