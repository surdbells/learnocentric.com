import { Component } from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {ToastrService} from "ngx-toastr";
import {RoutineCard} from "../../../../../components/routine-card/routine-card";
import {RoutineDayCard} from "../../../../../components/routine-day-card/routine-day-card";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {RoutineForm} from "../../../../../components/forms/routine-form/routine-form";
import {FormControl, FormGroup, Validators} from "@angular/forms";
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {ActivatedRoute} from '@angular/router';

@Component({
  selector: 'app-class-routine',
  imports: [
    PageHeader,
    RoutineCard,
    RoutineDayCard,
    LearnoModal,
    RoutineForm,
    DashboardCard
  ],
  templateUrl: './class-routine.html',
  styleUrl: './class-routine.css'
})
export class ClassRoutine {

    days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    userRole: string;


  constructor(
    private toastService: ToastrService,
    private route: ActivatedRoute,
  ) {
    this.userRole = this.route.snapshot.data['user'];
  }




    clickedHandler() {
        this.toastService.info('Clicked Class routine');
    }
}
