import {Component, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from "ngx-toastr";
import {Router} from "@angular/router";
import {AuthResponse, AuthUser} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';
import {ModuleAccessService} from '../../../common/auth/module-access.service';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-sign-in',
  imports: [
    ReactiveFormsModule,
    LearnoButton
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
        private moduleAccess: ModuleAccessService,
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
            this.auth = val
            this.authService.persistLogin(this.auth);
            this.moduleAccess.refresh(); // load the school's granted feature modules
            this.isLoading.set(false)
            this.form.reset()
            this.toast.success('Login Successful');

              const user = val.user as AuthUser;
              switch (user['role']) {
                case "school_admin":
                  this.router.navigate(["admin/main"]);
                  break;

                case "tutor_admin":
                  this.router.navigate(["academy/main"]);
                  break;

                case "teacher":
                  this.router.navigate(["teacher/main"]);
                  break;

                case "student":
                  this.router.navigate(["student/main"]);
                  break;

                case "parent":
                  this.router.navigate(["parent/main"]);
                  break;

                case "super_admin":
                  this.router.navigate(["super-admin/main"]);
                  break;

                default:
                  this.toast.error("Your account has no dashboard assigned. Please contact your administrator.");
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
