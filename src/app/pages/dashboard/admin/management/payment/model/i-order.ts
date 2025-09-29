export interface IOrder {
    id: number;
    product: string;
    date: string;
    status: "Completed" | "Pending" | "Cancelled";
    price: string;
}