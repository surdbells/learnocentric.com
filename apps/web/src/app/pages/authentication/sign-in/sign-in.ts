import {Component, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from "ngx-toastr";
import {Router, RouterLink} from "@angular/router";
import {portalFor} from '../../../common/auth/portal';
import {AuthResponse, AuthUser} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';
import {ModuleAccessService} from '../../../common/auth/module-access.service';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-sign-in',
  imports: [
    ReactiveFormsModule,
    LearnoButton,
    RouterLink
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
              const portal = portalFor(user['role']);
              if (portal) {
                this.router.navigateByUrl(portal);
              } else {
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
