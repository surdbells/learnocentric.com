import { Routes } from '@angular/router';
import {SignIn} from './pages/authentication/sign-in/sign-in';
import {Dashboard} from './pages/dashboard/dashboard';
import {Students} from "./pages/dashboard/admin/student/students/students";
import {NewStudent} from "./pages/dashboard/admin/student/new-student/new-student";
import {Teachers} from "./pages/dashboard/admin/teacher/teachers/teachers";
import {NewTeacher} from "./pages/dashboard/admin/teacher/new-teacher/new-teacher";
import {SchoolProfile} from "./pages/dashboard/admin/management/school-profile/school-profile";
import {Payment} from "./pages/dashboard/admin/management/payment/payment";
import {ClassRoutine} from "./pages/dashboard/admin/academics/class-routine/class-routine";
import {ELibrary} from "./pages/dashboard/admin/academics/e-library/e-library";
import {LessonPlans} from "./pages/dashboard/admin/academics/lesson-plans/lesson-plans";
import {SchoolClasses} from "./pages/dashboard/admin/academics/school-classes/school-classes";
import {Subjects} from "./pages/dashboard/admin/academics/subjects/subjects";
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
// import {CalendarComponent} from './calendar/calendar.component';

export const routes: Routes = [
  {
    path: "authentication",
    children: [
      { path: "", component: SignIn },
    ]
  },


    {
        path: "admin",
        component: Dashboard,
        children: [
          { path: "students", component: Students },
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
              { path: "class-routine", component: ClassRoutine },
              { path: "e-library", component: ELibrary },
              { path: "lesson-plans", component: LessonPlans },
              { path: "classes", component: SchoolClasses },
              { path: "subjects", component: Subjects },
              { path: "results", component: Result },
            ]
          }
        ]
    },

    {
        path: "admin/management",
        component: Dashboard,
        children: [
                { path: "school-profile", component: SchoolProfile },
                { path: "payment", component: Payment },
                { path: "payment/checkout", component: Checkout },
        ]
    },

    {
      path: "teacher",
      component: Dashboard,
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
            { path: "class-routine", component: ClassRoutine, data: { user: "teacher" } },
            { path: "e-library", component: ELibrary, data: { user: "teacher" } },
            { path: "lesson-plans", component: LessonPlans, data: { user: "teacher" } },
            { path: "grades", component: Grade },
            { path: "subjects", component: Subjects },
            { path: "results", component: Result },
            { path: "pending-task", component: PendingTask },
            { path: "assignments", component: Assignment },
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
            { path: "class-routine", component: ClassRoutine, data: { user: "student" } },
            { path: "e-library", component: ELibrary, data: { user: "student" } },
            { path: "lesson-plans", component: LessonPlans, data: { user: "student" } },
            { path: "grades", component: Grade },
            { path: "assignments", component: Assignment },
            { path: "assessments", component: StudentAssessment },
            { path: "performance", component: StudentPerformance }
          ]
        },
        {
          path: "management",
          component: StudentManagement,
          children: [
            { path: "profile", component: StudentProfile },
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


  // {
    //     path: "apps/message",
    //     component: Dashboard,
    //     children: [
    //         { path: "", component: Chat  },
    //     ]
    // },



];
