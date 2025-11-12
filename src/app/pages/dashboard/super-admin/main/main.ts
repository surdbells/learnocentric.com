import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-super-admin-main',
  standalone: true,
  imports: [RouterOutlet],
  templateUrl: './main.html',
  styleUrl: './main.css'
})
export class SuperAdminMain {}