import { Component } from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from "ngx-toastr";
import {Router} from "@angular/router";
import {AuthUser} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';

@Component({
  selector: 'app-sign-in',
    imports: [
        ReactiveFormsModule
    ],
  templateUrl: './sign-in.html',
  styleUrl: './sign-in.css'
})
export class SignIn {
    private app = 'learno'

    form = new FormGroup({
        email: new FormControl('', [Validators.email, Validators.required] ),
        password: new FormControl('',  [Validators.required] )
    })

    constructor(
        private toast: ToastrService,
        private router: Router,
        private authService: AuthService,
    ) { }

    login() {

       if(this.form.invalid) {
         this.toast.error('Invalid email or password');
         this.form.setValue({email: '', password: ''})
       }


       const users = [
         { id: "12344", name: "waheed", role: "student", email: "stu@gmail.com" },
         { id: "12345", name: "wede", role: "teacher", email: "teacher@gmail.com" },
         { id: "12346", name: "wedex", role: "school", email: "school@gmail.com" },
       ]

        // const user: AuthUser  = {
        //   id: "12344",
        //   name: "waheed",
        //   role: "student",
        //   email: "xyz@gmail.com",
        // }

        console.log(this.form.value)

      const user = users.find(el => el.email == this.form.value.email)
      if(user){

        this.authService.persistLogin({ token: "waitforit", user: user })

        switch (user['role']) {
          case "school":
            this.loginDelay("admin/main");
            break;

          case "teacher":
            this.loginDelay("teacher/main");
            break;

          case "student":
            this.loginDelay("student/main");
            break;

          default:
            this.loginDelay("authentication");
        }
      }


      else {
        this.toast.error("Incorrect email or password");
      }
        // localStorage.setItem(`user-${this.app}`, JSON.stringify(user))
    }

    private loginDelay (route: string) {
      this.toast.success('Login Successful')
      setTimeout(() => this.router.navigate([route]), 3000)
    }
}
