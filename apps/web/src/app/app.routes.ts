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
import {Safeguarding} from "./pages/dashboard/admin/management/safeguarding/safeguarding";
import {SchoolSettings} from "./pages/dashboard/admin/management/school-settings/school-settings";
import {SchoolClasses} from "./pages/dashboard/admin/academics/school-classes/school-classes";
import {Subjects} from "./pages/dashboard/admin/academics/subjects/subjects";
import {Topics} from "./pages/dashboard/admin/academics/topics/topics";
import {LessonContent} from "./pages/dashboard/admin/academics/lesson-content/lesson-content";
import {DeliveryPackDetail} from "./pages/dashboard/admin/academics/delivery-pack-detail/delivery-pack-detail";
import {SchemeOfWork} from "./pages/dashboard/admin/academics/scheme-of-work/scheme-of-work";
import {SchemeDetail} from "./pages/dashboard/admin/academics/scheme-detail/scheme-detail";
import {CurriculumMap} from "./pages/dashboard/admin/academics/curriculum-map/curriculum-map";
import {SchoolReport} from "./pages/dashboard/admin/academics/school-report/school-report";
import {ReportCards} from "./pages/dashboard/admin/academics/report-cards/report-cards";
import {ApprovalQueue} from "./pages/dashboard/admin/academics/approval-queue/approval-queue";
import {QuestionBank} from "./pages/dashboard/admin/academics/question-bank/question-bank";
import {Assessments} from "./pages/dashboard/admin/academics/assessments/assessments";
import {Gradebook} from "./pages/dashboard/admin/academics/gradebook/gradebook";
import {Worksheets} from "./pages/dashboard/admin/academics/worksheets/worksheets";
import {Portfolio} from "./pages/dashboard/admin/academics/portfolio/portfolio";
import {Insights} from "./pages/dashboard/admin/academics/insights/insights";
import {LiveClasses} from "./pages/dashboard/admin/academics/live-classes/live-classes";
import {Analytics} from "./pages/dashboard/admin/academics/analytics/analytics";
import {Interventions} from "./pages/dashboard/admin/academics/interventions/interventions";
import {AdminDashboard} from "./pages/dashboard/admin/main/dashboard/dashboard";
import {Main} from './pages/dashboard/admin/main/main';
import {Academics} from './pages/dashboard/admin/academics/academics';
import {TeacherMain} from './pages/dashboard/teacher/teacher-main/teacher-main';
import {TeacherDashboard} from './pages/dashboard/teacher/teacher-main/teacher-dashboard/teacher-dashboard';
import {Student} from './pages/dashboard/teacher/academics/student/student';
import {TeacherProfile} from './pages/dashboard/teacher/management/teacher-profile/teacher-profile';
import {TeacherManagement} from './pages/dashboard/teacher/management/management';
import {StudentMain} from './pages/dashboard/student/student-main/student-main';
import {StudentDashboard} from './pages/dashboard/student/student-main/student-dashboard/student-dashboard';
import {MyAssessments} from './pages/dashboard/student/academics/my-assessments/my-assessments';
import {MyWorksheets} from './pages/dashboard/student/academics/my-worksheets/my-worksheets';
import {MyPortfolio} from './pages/dashboard/student/academics/my-portfolio/my-portfolio';
import {MyFeedback} from './pages/dashboard/student/academics/my-feedback/my-feedback';
import {MyLiveClasses} from './pages/dashboard/student/academics/my-live-classes/my-live-classes';
import {Learn} from './pages/dashboard/student/academics/learn/learn';
import {ProgressReport} from './pages/dashboard/student/academics/progress-report/progress-report';
import {StudentManagement} from './pages/dashboard/student/management/management';
import {StudentProfile} from './pages/dashboard/student/management/student-profile/student-profile';
import {ReportConcern} from './pages/dashboard/student/report-concern/report-concern';
import {StudentSettings} from './pages/dashboard/student/settings/settings';
import {TeacherSettings} from './pages/dashboard/teacher/settings/settings';
import {MyClasses} from './pages/dashboard/teacher/academics/my-classes/my-classes';
import {FeedbackCompose} from './pages/dashboard/teacher/feedback-compose/feedback-compose';
import {authGuard} from './common/auth/auth-guard';
import {moduleGuard} from './common/auth/module-guard';
import {ParentMain} from './pages/dashboard/parent/parent-main/parent-main';
import {Parent} from './pages/dashboard/parent/parent';
import {Enrollment} from './pages/dashboard/admin/student/enrollment/enrollment';
import {SuperAdminDashboard} from './pages/dashboard/super-admin/main/dashboard/dashboard';
import {SuperAdminMain} from './pages/dashboard/super-admin/main/main';
import {SuperAdminInstitutions} from './pages/dashboard/super-admin/institutions/institutions';
import {SuperAdminOnboard} from './pages/dashboard/super-admin/institutions/onboard/onboard';
import {SuperAdminContentLibrary} from './pages/dashboard/super-admin/content-library/content-library';
import {SuperAdminContentPackages} from './pages/dashboard/super-admin/content-packages/content-packages';
import {SuperAdminPlans} from './pages/dashboard/super-admin/plans/plans';
import {SuperAdminAuditLogs} from './pages/dashboard/super-admin/audit-logs/audit-logs';
import {SuperAdminUsersRoles} from './pages/dashboard/super-admin/users-roles/users-roles';
import {SuperAdminAnalytics} from './pages/dashboard/super-admin/analytics/analytics'; // platform analytics (Phase 5)
import {SuperAdminReports} from './pages/dashboard/super-admin/reports/reports';
import {SuperAdminSystemSettings} from './pages/dashboard/super-admin/system-settings/system-settings';
import {SuperAdminSafeguarding} from './pages/dashboard/super-admin/safeguarding/safeguarding';
import {SuperAdminBilling} from './pages/dashboard/super-admin/billing/billing';
import {Calendar} from './pages/dashboard/admin/calendar/calendar';
import {SchoolSetup} from './pages/dashboard/admin/school-setup/school-setup';
import {CatalogSubjects} from './pages/dashboard/super-admin/catalog-subjects/catalog-subjects';
import {Support} from './pages/dashboard/support/support';
import {Resources} from './pages/dashboard/resources/resources';
import {ResourceViewer} from './pages/dashboard/resources/resource-viewer/resource-viewer';
import {Messages} from './pages/dashboard/messages/messages';
import {Announcements} from './pages/dashboard/announcements/announcements';
import {AnnouncementCompose} from './pages/dashboard/announcements/announcement-compose/announcement-compose';

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
      { path: "setup", component: SchoolSetup },
      { path: "calendar", component: Calendar },
      { path: "students", component: Students },
      { path: "enrollments", component: Enrollment },
      { path: "students/new", component: NewStudent },
      { path: "teachers", component: Teachers },
      { path: "teachers/new", component: NewTeacher },
      {
        path: "main",
        component: Main,
        children: [
          { path: "", component: AdminDashboard },
        ]
      },
      {
        path: "academics",
        component: Academics,
        children: [
          { path: "classes", component: SchoolClasses, data: { user: "admin" } },
          { path: "subjects", component: Subjects, data: { user: "admin" } },
          { path: "topics", component: Topics, data: { user: "admin" } },
          { path: "lesson-content", component: LessonContent, data: { user: "admin" } },
          { path: "delivery-pack", component: DeliveryPackDetail, data: { user: "admin" } },
          { path: "scheme-of-work", component: SchemeOfWork, data: { user: "admin" } },
          { path: "scheme-coverage", component: SchemeDetail, data: { user: "admin" } },
          { path: "curriculum-map", component: CurriculumMap, data: { user: "admin" } },
          { path: "approval-queue", component: ApprovalQueue, data: { user: "admin" } },
          { path: "school-report", component: SchoolReport, data: { user: "admin" } },
          { path: "report-cards", component: ReportCards, data: { user: "admin" } },
          { path: "question-bank", component: QuestionBank, canActivate: [moduleGuard('assessments')], data: { user: "admin" } },
          { path: "assessments", component: Assessments, canActivate: [moduleGuard('assessments')], data: { user: "admin" } },
          { path: "worksheets", component: Worksheets, canActivate: [moduleGuard('worksheets')], data: { user: "admin" } },
          { path: "portfolio", component: Portfolio, canActivate: [moduleGuard('portfolio')], data: { user: "admin" } },
          { path: "live-classes", component: LiveClasses, canActivate: [moduleGuard('live_classes')], data: { user: "admin" } },
          { path: "gradebook", component: Gradebook, canActivate: [moduleGuard('assessments')], data: { user: "admin" } },
          { path: "insights", component: Insights, data: { user: "admin" } },
          { path: "analytics", component: Analytics, canActivate: [moduleGuard('analytics')], data: { user: "admin" } },
          { path: "interventions", component: Interventions, canActivate: [moduleGuard('interventions')], data: { user: "admin" } },
          { path: "resources", component: Resources, data: { user: "admin" } },
          { path: "resource-viewer", component: ResourceViewer, data: { user: "admin" } },
        ]
      },
      {
        path: "management",
        children: [
          { path: "school-profile", component: SchoolProfile },
          { path: "settings", component: SchoolSettings },
          { path: "billing", component: Billing },
          { path: "safeguarding", component: Safeguarding, canActivate: [moduleGuard('safeguarding')] },
          { path: "support", component: Support },
        ]
      },
      {
        path: "communication",
        children: [
          { path: "messages", component: Messages },
          { path: "announcements", component: Announcements },
          { path: "announcements/new", component: AnnouncementCompose },
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
      { path: "setup", component: SchoolSetup },
      { path: "calendar", component: Calendar },
      { path: "students", component: Students },
      { path: "enrollments", component: Enrollment },
      { path: "students/new", component: NewStudent },
      { path: "tutors", component: Teachers, data: { user: "academy" } },
      { path: "tutors/new", component: NewTeacher, data: { user: "academy" } },
      {
        path: "main",
        component: Main,
        data: { user: "tutor_admin" },
        children: [
          { path: "", component: AdminDashboard },
        ]
      },
      {
        path: "academics",
        component: Academics,
        data: { user: "tutor_admin" },
        children: [
          { path: "classes", component: SchoolClasses },
          { path: "subjects", component: Subjects },
          { path: "topics", component: Topics },
          { path: "lesson-content", component: LessonContent },
          { path: "delivery-pack", component: DeliveryPackDetail },
          { path: "scheme-of-work", component: SchemeOfWork },
          { path: "scheme-coverage", component: SchemeDetail },
          { path: "curriculum-map", component: CurriculumMap },
          { path: "approval-queue", component: ApprovalQueue },
          { path: "school-report", component: SchoolReport },
          { path: "report-cards", component: ReportCards },
          { path: "question-bank", component: QuestionBank, canActivate: [moduleGuard('assessments')] },
          { path: "assessments", component: Assessments, canActivate: [moduleGuard('assessments')] },
          { path: "worksheets", component: Worksheets, canActivate: [moduleGuard('worksheets')] },
          { path: "portfolio", component: Portfolio, canActivate: [moduleGuard('portfolio')] },
          { path: "live-classes", component: LiveClasses, canActivate: [moduleGuard('live_classes')] },
          { path: "gradebook", component: Gradebook, canActivate: [moduleGuard('assessments')] },
          { path: "insights", component: Insights },
          { path: "analytics", component: Analytics, canActivate: [moduleGuard('analytics')] },
          { path: "interventions", component: Interventions, canActivate: [moduleGuard('interventions')] },
          { path: "resources", component: Resources },
          { path: "resource-viewer", component: ResourceViewer },
        ]
      },
      {
        path: "management",
        data: { user: "tutor_admin" },
        children: [
          { path: "academy-profile", component: SchoolProfile },
          { path: "settings", component: SchoolSettings },
          { path: "billing", component: Billing },
          { path: "safeguarding", component: Safeguarding, canActivate: [moduleGuard('safeguarding')] },
          { path: "support", component: Support },
        ]
      },
      {
        path: "communication",
        data: { user: "tutor_admin" },
        children: [
          { path: "messages", component: Messages },
          { path: "announcements", component: Announcements },
          { path: "announcements/new", component: AnnouncementCompose },
        ]
      }
    ]
  },

  {
    path: "teacher",
    component: Dashboard,
    canActivate: [authGuard],
    children: [
      {
        path: "main",
        component: TeacherMain,
        children: [
          { path: "", component: TeacherDashboard },
          { path: "students", component: Student },
        ]
      },
      { path: "classes", component: MyClasses },
      { path: "feedback", component: FeedbackCompose },
      {
        path: "academics",
        component: Academics,
        children: [
          { path: "topics", component: Topics },
          { path: "lesson-content", component: LessonContent },
          { path: "delivery-pack", component: DeliveryPackDetail },
          { path: "scheme-of-work", component: SchemeOfWork },
          { path: "scheme-coverage", component: SchemeDetail },
          { path: "curriculum-map", component: CurriculumMap },
          { path: "approval-queue", component: ApprovalQueue },
          { path: "school-report", component: SchoolReport },
          { path: "report-cards", component: ReportCards },
          { path: "question-bank", component: QuestionBank, canActivate: [moduleGuard('assessments')] },
          { path: "assessments", component: Assessments, canActivate: [moduleGuard('assessments')] },
          { path: "worksheets", component: Worksheets, canActivate: [moduleGuard('worksheets')] },
          { path: "portfolio", component: Portfolio, canActivate: [moduleGuard('portfolio')] },
          { path: "live-classes", component: LiveClasses, canActivate: [moduleGuard('live_classes')] },
          { path: "gradebook", component: Gradebook, canActivate: [moduleGuard('assessments')] },
          { path: "insights", component: Insights },
          { path: "analytics", component: Analytics, canActivate: [moduleGuard('analytics')] },
          { path: "interventions", component: Interventions, canActivate: [moduleGuard('interventions')] },
          { path: "safeguarding", component: Safeguarding, canActivate: [moduleGuard('safeguarding')] },
          { path: "resources", component: Resources },
          { path: "resource-viewer", component: ResourceViewer },
        ]
      },
      {
        path: "management",
        component: TeacherManagement,
        children: [
          { path: "profile", component: TeacherProfile },
        ]
      },
      { path: "settings", component: TeacherSettings },
      {
        path: "communication",
        children: [
          { path: "messages", component: Messages },
          { path: "announcements", component: Announcements },
          { path: "announcements/new", component: AnnouncementCompose },
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
        component: StudentMain,
        children: [
          { path: "", component: StudentDashboard },
        ]
      },
      {
        path: "academics",
        component: Academics,
        children: [
          { path: "learn", component: Learn },
          { path: "assessments", component: MyAssessments, canActivate: [moduleGuard('assessments')] },
          { path: "worksheets", component: MyWorksheets, canActivate: [moduleGuard('worksheets')] },
          { path: "portfolio", component: MyPortfolio, canActivate: [moduleGuard('portfolio')] },
          { path: "feedback", component: MyFeedback },
          { path: "live-classes", component: MyLiveClasses, canActivate: [moduleGuard('live_classes')] },
          { path: "resources", component: Resources },
          { path: "resource-viewer", component: ResourceViewer },
          { path: "progress-report", component: ProgressReport, canActivate: [moduleGuard('analytics')] },
        ]
      },
      {
        path: "management",
        component: StudentManagement,
        children: [
          { path: "profile", component: StudentProfile },
        ]
      },
      { path: "report-concern", component: ReportConcern },
      { path: "settings", component: StudentSettings },
      {
        path: "communication",
        children: [
          { path: "messages", component: Messages },
          { path: "announcements", component: Announcements },
          { path: "announcements/new", component: AnnouncementCompose },
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
        ]
      },
      {
        path: "management",
        component: StudentManagement,
        children: [
          { path: "institutions", component: SuperAdminInstitutions },
          { path: "onboard", component: SuperAdminOnboard },
          { path: "content-library", component: SuperAdminContentLibrary },
          { path: "subjects", component: CatalogSubjects },
          { path: "content-packages", component: SuperAdminContentPackages },
          { path: "plans", component: SuperAdminPlans },
          { path: "support", component: Support },
          { path: "users-roles", component: SuperAdminUsersRoles },
          { path: "audit-logs", component: SuperAdminAuditLogs },
          { path: "analytics", component: SuperAdminAnalytics },
          { path: "reports", component: SuperAdminReports },
          { path: "system-settings", component: SuperAdminSystemSettings },
          { path: "safeguarding", component: SuperAdminSafeguarding },
          { path: "billing", component: SuperAdminBilling }
        ]
      }
    ]
  },

  {
    path: "parent",
    component: Dashboard,
    canActivate: [authGuard],
    children: [
      {
        path: "main",
        component: Parent,
        children: [
          { path: "", component: ParentMain },
        ]
      },
      {
        path: "academics",
        component: Academics,
        children: [
          { path: "progress-report", component: ProgressReport, canActivate: [moduleGuard('analytics')] },
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
        path: "communication",
        children: [
          { path: "messages", component: Messages },
          { path: "announcements", component: Announcements },
          { path: "announcements/new", component: AnnouncementCompose },
        ]
      }
    ]
  },
];
