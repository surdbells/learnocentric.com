import {Component, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from "ngx-toastr";
import {Router} from "@angular/router";
import {AuthResponse, AuthUser} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';
import {ApiService} from '../../../common/service/api.service';
import {Loader} from '../../../common/loader/loader';
import {NgIf} from '@angular/common';

@Component({
  selector: 'app-sign-in',
  imports: [
    ReactiveFormsModule,
    Loader,
    NgIf
  ],
  templateUrl: './sign-in.html',
  styleUrl: './sign-in.css'
})
export class SignIn {
  private app = 'learno'
  private auth: AuthResponse | null = null;

  isLoading = signal<boolean>(false)

    form = new FormGroup({
        email: new FormControl('', [Validators.email, Validators.required] ),
        password: new FormControl('',  [Validators.required] )
    })

    constructor(
        private toast: ToastrService,
        private router: Router,
        private authService: AuthService,
        private apiService: ApiService,
    ) { }

    login() {

        this.isLoading.set(true)

       if(this.form.invalid) {
         this.toast.error('Invalid email or password');
         this.form.setValue({email: '', password: ''})
       }

      this.apiService.post(`/backend/auth/login`, this.form.value).subscribe(
        {
          next: (val: AuthResponse) => {
            console.log(val);
            this.auth = val
            this.authService.persistLogin(this.auth);
            this.isLoading.set(false)
            this.form.reset()
            this.toast.success('Login Successful');

              const user = val.user as AuthUser;
              switch (user['role']) {
                case "school_admin":
                  this.router.navigate(["admin/main"]);
                  break;

                case "teacher":
                  this.router.navigate(["teacher/main"]);
                  break;

                case "student":
                  this.router.navigate(["student/main"]);
                  break;

                default:
                  this.router.navigate(["authentication"]);
              }

          },
        error: (err) => {
          console.log(err)
          this.isLoading.set(false)
          this.toast.error("Incorrect email or password");
          return;
        },
          complete: () => {
          this.isLoading.set(false)
          }
        }
      );
    }
}
