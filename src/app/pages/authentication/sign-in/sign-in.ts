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

        const user: AuthUser  = {
          id: "12344",
          name: "waheed",
          role: "teacher",
          email: "xyz@gmail.com",
        }

        console.log(this.form.value)

      if(this.form.value.email == user.email){
        this.authService.persistLogin({ token: "waitforit", user: user })

        switch (user['role']) {
          case "school":
            this.loginDelay("admin/main");
            break;

          case "teacher":
            this.loginDelay("teacher/main");
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
