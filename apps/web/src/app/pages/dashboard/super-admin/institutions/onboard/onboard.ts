import {Component, OnInit, signal} from '@angular/core';
import {ReactiveFormsModule, FormGroup, FormControl, Validators} from '@angular/forms';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {Router} from '@angular/router';
import { InstitutionForm } from "../../../../../components/forms/institution-form/institution-form";

@Component({
  selector: 'app-super-admin-onboard',
  standalone: true,
  imports: [ReactiveFormsModule, PageHeader, InstitutionForm],
  templateUrl: './onboard.html',
  styleUrl: './onboard.css'
})
export class SuperAdminOnboard implements OnInit {
  ngOnInit(): void {
    // throw new Error('Method not implemented.');
  }

}
