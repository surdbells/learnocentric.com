import { Injectable } from '@angular/core';
import {AuthUser} from '../auth/auth.models';

@Injectable({
  providedIn: 'root'
})
export class UtilService {

  groupRoutineToEachDay(routines: any[]): {[key:number]: any[]}[] {
    //const routines = this.teacherRoutines();
    const groupRoutine = new Map<number, any[]>();
    routines.forEach((routine) => {
      const day = parseInt(routine.day_of_week);
      if (!groupRoutine.has(day)) {
        groupRoutine.set(day, []);
      }
      groupRoutine.get(day)?.push(routine);
    });
    return Array.from(groupRoutine.entries()).map(([day, routines]) => ({
      day,
      routines
    }))
  }


  configureForOption(data: any[]) {
    return data.map((item: any) => {
      if(item.hasOwnProperty('first_name')) {
        return {
          ...item,
          value: item.id,
          label: item.first_name + ' ' + item.last_name
        };
      }
      return {
        ...item,
        value: item.id,
        label: item.name
      };
    })
  }

  getTeacherFullname(user: AuthUser): string {
    if(!user) return '';
    return user["firstName"] + ' ' + user["lastName"];
  }

}
