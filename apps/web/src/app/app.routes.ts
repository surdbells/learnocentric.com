import { Routes } from '@angular/router';
import {SignIn} from './pages/authentication/sign-in/sign-in';
import {Dashboard} from './pages/dashboard/dashboard';
import {Notifications} from './pages/dashboard/notifications/notifications';
import {Students} from "./pages/dashboard/admin/student/students/students";
import {NewStudent} from "./pages/dashboard/admin/student/new-student/new-student";
import {Teachers} from "./pages/dashboard/admin/teacher/teachers/teachers";
import {NewTeacher} from "./pages/dashboard/admin/teacher/new-teacher/new-teacher";
import {SchoolProfile} from "./pages/dashboard/admin/management/school-profile/school-profile";
import {Billing} from "./pages/dashboard/admin/management/billing/billing";
import {Payment} from "./pages/dashboard/admin/management/payment/payment";
import {ClassRoutine} from "./pages/dashboard/admin/academics/class-routine/class-routine";
import {ELibrary} from "./pages/dashboard/admin/academics/e-library/e-library";
import {LessonPlans} from "./pages/dashboard/admin/academics/lesson-plans/lesson-plans";
import {SchoolClasses} from "./pages/dashboard/admin/academics/school-classes/school-classes";
import {Subjects} from "./pages/dashboard/admin/academics/subjects/subjects";
import {Topics} from "./pages/dashboard/admin/academics/topics/topics";
import {LessonContent} from "./pages/dashboard/admin/academics/lesson-content/lesson-content";
import {SchemeOfWork} from "./pages/dashboard/admin/academics/scheme-of-work/scheme-of-work";
import {QuestionBank} from "./pages/dashboard/admin/academics/question-bank/question-bank";
import {Assessments} from "./pages/dashboard/admin/academics/assessments/assessments";
import {Gradebook} from "./pages/dashboard/admin/academics/gradebook/gradebook";
import {Worksheets} from "./pages/dashboard/admin/academics/worksheets/worksheets";
import {Portfolio} from "./pages/dashboard/admin/academics/portfolio/portfolio";
import {Insights} from "./pages/dashboard/admin/academics/insights/insights";
import {LiveClasses} from "./pages/dashboard/admin/academics/live-classes/live-classes";
import {Analytics} from "./pages/dashboard/admin/academics/analytics/analytics";
import {Interventions} from "./pages/dashboard/admin/academics/interventions/interventions";
import {Checkout} from "./pages/dashboard/admin/management/payment/checkout/checkout";
import {Result} from "./pages/dashboard/admin/academics/result/result";
import {Chat} from "./pages/apps/chat/chat";
import {FileManager} from "./pages/file-manager/file-manager";
import {MyDrive} from "./pages/file-manager/my-drive/my-drive";
import {Assets} from "./pages/file-manager/assets/assets";
import {Templates} from "./pages/file-manager/templates/templates";
import {Projects} from "./pages/file-manager/projects/projects";
import {Documents} from "./pages/file-manager/documents/documents";
import {Media} from "./pages/file-manager/media/media";
import {AdminDashboard} from "./pages/dashboard/admin/main/dashboard/dashboard";
import {Main} from './pages/dashboard/admin/main/main';
import {Academics} from './pages/dashboard/admin/academics/academics';
import {TeacherMain} from './pages/dashboard/teacher/teacher-main/teacher-main';
import {TeacherDashboard} from './pages/dashboard/teacher/teacher-main/teacher-dashboard/teacher-dashboard';
import {Notication} from './pages/apps/notication/notication';
import {Student} from './pages/dashboard/teacher/academics/student/student';
import {SchoolEvent} from './pages/apps/school-event/school-event';
import {CalendarComponent} from './pages/apps/calendar/calendar.component';
import {PendingTask} from './pages/dashboard/teacher/academics/pending-task/pending-task';
import {Assignment} from './pages/dashboard/teacher/academics/assignment/assignment';
import {Attendance} from './pages/dashboard/teacher/academics/attendance/attendance';
import {Assessment} from './pages/dashboard/teacher/academics/assessment/assessment';
import {VirtualClass} from './pages/dashboard/teacher/academics/virtual-class/virtual-class';
import {Promotion} from './pages/dashboard/teacher/academics/promotion/promotion';
import {Grade} from './pages/dashboard/teacher/academics/grade/grade';
import {TeacherProfile} from './pages/dashboard/teacher/management/teacher-profile/teacher-profile';
import {TeacherManagement} from './pages/dashboard/teacher/management/management';
import {StudentMain} from './pages/dashboard/student/student-main/student-main';
import {StudentDashboard} from './pages/dashboard/student/student-main/student-dashboard/student-dashboard';
import {Classmate} from './pages/dashboard/student/student-main/classmate/classmate';
import {StudentAssessment} from './pages/dashboard/student/academics/student-assessment/student-assessment';
import {MyAssessments} from './pages/dashboard/student/academics/my-assessments/my-assessments';
import {MyWorksheets} from './pages/dashboard/student/academics/my-worksheets/my-worksheets';
import {MyPortfolio} from './pages/dashboard/student/academics/my-portfolio/my-portfolio';
import {MyFeedback} from './pages/dashboard/student/academics/my-feedback/my-feedback';
import {MyLiveClasses} from './pages/dashboard/student/academics/my-live-classes/my-live-classes';
import {Learn} from './pages/dashboard/student/academics/learn/learn';
import {ProgressReport} from './pages/dashboard/student/academics/progress-report/progress-report';
import {StudentPerformance} from './pages/dashboard/student/academics/student-performance/student-performance';
import {StudentManagement} from './pages/dashboard/student/management/management';
import {StudentProfile} from './pages/dashboard/student/management/student-profile/student-profile';
import {Lesson} from './pages/dashboard/student/lesson/lesson';
import {StudentLearning} from './pages/dashboard/student/lesson/student-learning/student-learning';
import {StudentAssignment} from './pages/dashboard/student/lesson/student-assignment/student-assignment';
import {StudentVirtualClass} from './pages/dashboard/student/lesson/student-virtual-class/student-virtual-class';
import {StudentResources} from './pages/dashboard/student/lesson/student-resources/student-resources';
import {StudentDiscussion} from './pages/dashboard/student/lesson/student-discussion/student-discussion';
import {StudentNotes} from './pages/dashboard/student/lesson/student-notes/student-notes';
import {StudentAssessment as StudentLessonAssessment} from './pages/dashboard/student/lesson/student-assessment/student-assessment';
import {
  StudentChatWithTeacher
} from './pages/dashboard/student/lesson/student-chat-with-teacher/student-chat-with-teacher';
import {authGuard} from './common/auth/auth-guard';
import {Fees} from './pages/dashboard/admin/management/fees/fees';
import {ParentMain} from './pages/dashboard/parent/parent-main/parent-main';
import {Parent} from './pages/dashboard/parent/parent';
import {Enrollment} from './pages/dashboard/admin/student/enrollment/enrollment';
import {StudentClassRoutine} from './pages/dashboard/student/academics/student-class-routine/student-class-routine';
import {TeacherClassRoutine} from './pages/dashboard/teacher/academics/teacher-class-routine/teacher-class-routine';
import {StudentPayment} from './pages/dashboard/student/management/payment/student-payment.component';
import {StudentFee} from './pages/dashboard/student/management/student-fee/student-fee';
// import {CalendarComponent} from './calendar/calendar.component';
import {SuperAdminDashboard} from './pages/dashboard/super-admin/main/dashboard/dashboard';
import {SuperAdminMain} from './pages/dashboard/super-admin/main/main';
import {SuperAdminInstitutions} from './pages/dashboard/super-admin/institutions/institutions';
import {SuperAdminOnboard} from './pages/dashboard/super-admin/institutions/onboard/onboard';
import {SuperAdminContentLibrary} from './pages/dashboard/super-admin/content-library/content-library';
import {SuperAdminContentPackages} from './pages/dashboard/super-admin/content-packages/content-packages';
import {SuperAdminPlans} from './pages/dashboard/super-admin/plans/plans';

export const routes: Routes = [
  {
    path: "",
    children: [
      { path: "", component: SignIn },
    ]
  },

  {
    path: "notifications",
    component: Dashboard,
    canActivate: [authGuard],
    children: [
      { path: "", component: Notifications },
    ]
  },


    {
        path: "admin",
        component: Dashboard,
        canActivate: [authGuard],
        children: [
          { path: "students", component: Students },
          { path: "enrollments", component: Enrollment },
          { path: "students/new", component: NewStudent },
          { path: "teachers", component: Teachers },
          { path: "teachers/new", component: NewTeacher },
          {
            path: "main",
            component:  Main,
            children: [
              { path: "", component: AdminDashboard },
              { path: "message", component: Chat },
              {
                path: "file-manager",
                component: FileManager,
                children: [
                  { path: "", component: MyDrive },
                  { path: "assets", component: Assets },
                  { path: "template", component: Templates },
                  { path: "projects", component: Projects },
                  { path: "documents", component: Documents },
                  { path: "media", component: Media }
                ]
              }
            ]

          },
          {
            path: "academics",
            component: Academics,
            children: [
              { path: "class-routine", component: ClassRoutine, data: { user: "admin" }  },
              { path: "e-library", component: ELibrary, data: { user: "admin" }  },
              { path: "lesson-plans", component: LessonPlans, data: { user: "admin" }  },
              { path: "classes", component: SchoolClasses, data: { user: "admin" }  },
              { path: "subjects", component: Subjects, data: { user: "admin" }  },
              { path: "topics", component: Topics, data: { user: "admin" }  },
              { path: "lesson-content", component: LessonContent, data: { user: "admin" }  },
              { path: "scheme-of-work", component: SchemeOfWork, data: { user: "admin" }  },
              { path: "question-bank", component: QuestionBank, data: { user: "admin" }  },
              { path: "assessments", component: Assessments, data: { user: "admin" }  },
              { path: "worksheets", component: Worksheets, data: { user: "admin" }  },
              { path: "portfolio", component: Portfolio, data: { user: "admin" }  },
              { path: "insights", component: Insights, data: { user: "admin" }  },
              { path: "live-classes", component: LiveClasses, data: { user: "admin" }  },
              { path: "analytics", component: Analytics, data: { user: "admin" }  },
              { path: "interventions", component: Interventions, data: { user: "admin" }  },
              { path: "gradebook", component: Gradebook, data: { user: "admin" }  },
              { path: "results", component: Result, data: { user: "admin" }  },
            ]
          }
        ]
    },

    {
      path: "academy",
      component: Dashboard,
      canActivate: [authGuard],
      data: { user: "tutor_admin" },
      children: [
        { path: "students", component: Students, },
        { path: "enrollments", component: Enrollment },
        { path: "students/new", component: NewStudent },
        { path: "tutors", component: Teachers,  data: { user: "academy" }  },
        { path: "tutors/new", component: NewTeacher,  data: { user: "academy" } },
        {
          path: "main",
          component:  Main,
          data: { user: "tutor_admin" },
          children: [
            { path: "", component: AdminDashboard },
            { path: "message", component: Chat },
            {
              path: "file-manager",
              component: FileManager,
              children: [
                { path: "", component: MyDrive },
                { path: "assets", component: Assets },
                { path: "template", component: Templates },
                { path: "projects", component: Projects },
                { path: "documents", component: Documents },
                { path: "media", component: Media }
              ]
            }
          ]
        },
        {
          path: "academics",
          component: Academics,
          data: { user: "tutor_admin" },
          children: [
            { path: "class-routine", component: ClassRoutine, data: { user: 'academy' } },
            { path: "e-library", component: ELibrary },
            { path: "lesson-plans", component: LessonPlans },
            { path: "classes", component: SchoolClasses },
            { path: "subjects", component: Subjects },
            { path: "topics", component: Topics },
            { path: "lesson-content", component: LessonContent },
            { path: "scheme-of-work", component: SchemeOfWork },
            { path: "question-bank", component: QuestionBank },
            { path: "assessments", component: Assessments },
            { path: "worksheets", component: Worksheets },
            { path: "portfolio", component: Portfolio },
            { path: "insights", component: Insights },
            { path: "live-classes", component: LiveClasses },
            { path: "analytics", component: Analytics },
            { path: "interventions", component: Interventions },
            { path: "gradebook", component: Gradebook },
            { path: "results", component: Result },
          ]
        },
        {
          path: "management",
          data: { user: "tutor_admin" },
          children: [
            { path: "academy-profile", component: SchoolProfile },
            { path: "payment", component: Payment },
            { path: "payment/checkout", component: Checkout },
            { path: "subscription", component: Fees },
            { path: "billing", component: Billing },
          ]
        }

      ]
    },

    {
        path: "admin/management",
        component: Dashboard,
        canActivate: [authGuard],
        children: [
                { path: "school-profile", component: SchoolProfile },
                { path: "payment", component: Payment },
                { path: "payment/checkout", component: Checkout },
                { path: "fees", component: Fees },
                { path: "billing", component: Billing },
        ]
    },

    {
      path: "teacher",
      component: Dashboard,
      canActivate: [authGuard],
      children: [
        {
          path: "main",
          component:  TeacherMain,
          children: [
            { path: "", component: TeacherDashboard },
            { path: "message", component: Chat },
            { path: "notification", component: Notication },
            { path: "events", component: CalendarComponent },
            { path: "students", component: Student },
            {
              path: "file-manager",
              component: FileManager,
              children: [
                { path: "", component: MyDrive },
                { path: "assets", component: Assets },
                { path: "template", component: Templates },
                { path: "projects", component: Projects },
                { path: "documents", component: Documents },
                { path: "media", component: Media }
              ]
            }
          ]
        },
        {
          path: "academics",
          component: Academics,
          children: [
            { path: "class-routine", component: TeacherClassRoutine },
            { path: "e-library", component: ELibrary, data: { user: "teacher" } },
            { path: "lesson-plans", component: LessonPlans, data: { user: "teacher" } },
            { path: "grades", component: Grade },
            { path: "subjects", component: Subjects },
            { path: "topics", component: Topics },
            { path: "lesson-content", component: LessonContent },
            { path: "scheme-of-work", component: SchemeOfWork },
            { path: "question-bank", component: QuestionBank },
            { path: "assessments", component: Assessments },
            { path: "worksheets", component: Worksheets },
            { path: "portfolio", component: Portfolio },
            { path: "insights", component: Insights },
            { path: "live-classes", component: LiveClasses },
            { path: "analytics", component: Analytics },
            { path: "interventions", component: Interventions },
            { path: "gradebook", component: Gradebook },
            { path: "results", component: Result },
            { path: "pending-task", component: PendingTask },
            { path: "assignments", component: Assignment, data: { user: "teacher" } },
            { path: "attendance", component: Attendance },
            { path: "assessments", component: Assessment },
            { path: "virtual-class", component: VirtualClass },
            { path: "promotion", component: Promotion }
          ]
        },
        {
          path: "management",
          component: TeacherManagement,
          children: [
            { path: "profile", component: TeacherProfile },
          ]
        }
      ]
    },


    {
      path: "student",
      component: Dashboard,
      canActivate: [authGuard],
      children: [
        {
          path: "main",
          component:  StudentMain,
          children: [
            { path: "", component: StudentDashboard },
            { path: "message", component: Chat },
            { path: "notification", component: Notication },
            { path: "events", component: CalendarComponent },
            { path: "classmate", component: Classmate },
            {
              path: "file-manager",
              component: FileManager,
              children: [
                { path: "", component: MyDrive },
                { path: "assets", component: Assets },
                { path: "template", component: Templates },
                { path: "projects", component: Projects },
                { path: "documents", component: Documents },
                { path: "media", component: Media }
              ]
            }
          ]

        },
        {
          path: "academics",
          component: Academics,
          children: [
            { path: "class-routine", component: StudentClassRoutine },
            { path: "e-library", component: ELibrary, data: { user: "student" } },
            { path: "lesson-plans", component: LessonPlans, data: { user: "student" } },
            { path: "grades", component: Grade },
            { path: "assignments", component: Assignment, data: { user: "student" } },
            { path: "learn", component: Learn },
            { path: "assessments", component: MyAssessments },
            { path: "worksheets", component: MyWorksheets },
            { path: "portfolio", component: MyPortfolio },
            { path: "feedback", component: MyFeedback },
            { path: "live-classes", component: MyLiveClasses },
            { path: "progress-report", component: ProgressReport },
            { path: "performance", component: StudentPerformance }
          ]
        },
        {
          path: "management",
          component: StudentManagement,
          children: [
            { path: "profile", component: StudentProfile },
            { path: "payment", component: StudentPayment },
            { path: "fee", component: StudentFee },
          ]
        },

        {
          path: "lesson/:subjectId",
          component: Lesson,
          children: [
            { path: "", component: StudentLearning },
            { path: "assignment", component: StudentAssignment },
            { path: "virtual-class", component: StudentVirtualClass },
            { path: "resources", component: StudentResources },
            { path: "discussion", component: StudentDiscussion },
            { path: "notes", component: StudentNotes },
            { path: "assessment", component: StudentLessonAssessment },
            { path: "chat-with-teacher", component: StudentChatWithTeacher },

          ]
        }
      ]
    },

    {
      path: "super-admin",
      component: Dashboard,
      canActivate: [authGuard],
      children: [
        {
          path: "main",
          component: SuperAdminMain,
          children: [
            { path: "", component: SuperAdminDashboard },
            { path: "message", component: Chat },
            {
              path: "file-manager",
              component: FileManager,
              children: [
                { path: "", component: MyDrive },
                { path: "assets", component: Assets },
                { path: "template", component: Templates },
                { path: "projects", component: Projects },
                { path: "documents", component: Documents },
                { path: "media", component: Media }
              ]
            }
          ]
        },
                {
            path: "management",
            component: StudentManagement,
            children: [
              { path: "institutions", component: SuperAdminInstitutions },
              { path: "onboard", component: SuperAdminOnboard },
              { path: "content-library", component: SuperAdminContentLibrary },
              { path: "content-packages", component: SuperAdminContentPackages },
              { path: "plans", component: SuperAdminPlans }
            ]
          },

      ]
    },

    {
      path: "parent",
      component: Dashboard,
      canActivate: [authGuard], 
      children: [
        {
          path: "main",
          component:  Parent,
          children: [
            { path: "", component: ParentMain },
          ]

        },
        {
          path: "academics",
          component: Academics,
          children: [
            { path: "grades", component: Grade },
          ]
        },
        {
          path: "management",
          component: StudentManagement,
          children: [
            { path: "profile", component: StudentProfile },
          ]
        },
      ]
    },



  // {
    //     path: "apps/message",
    //     component: Dashboard,
    //     children: [
    //         { path: "", component: Chat  },
    //     ]
    // },



];
