import { Injectable } from '@angular/core';

export interface IMenu {
  name: string;
  icon?: string;
  link: string;
  /** Gateable feature module this item needs; hidden when the plan doesn't grant it. */
  module?: string;
  children?: IMenu[];
}

@Injectable({
  providedIn: 'root'
})
export class UserPreferenceMenu {

  // Compact, consolidated admin sidebar (~13 top-level entries) modelled on the
  // learner menu and the School-Admin review PDF. Sprawling groups are folded
  // into named hubs; as each hub page is built (tabbed pages in later phases),
  // its group collapses to a single flat link. Every existing page stays
  // reachable here, so no routes change in this phase.
  school_admin: IMenu[]= [
    { name: "Dashboard", icon: "dashboard", link: "/admin/main" },

    { name: "School Setup", icon: "school_setup", link: "/admin/setup" },

    // Tabbed hub: Classes & Learners + Students + Enrollment on one page.
    { name: "Classes & Learners", icon: "group", link: "/admin/classes-learners" },

    { name: "Teachers & Staff", icon: "supervisor_account", link: "/admin/teachers" },

    // Tabbed hub: Subjects, Topics, Lesson Content, Delivery Pack, Scheme of
    // Work, Scheme Coverage, Curriculum Map and Approval Queue on one page.
    { name: "Subjects & Curriculum", icon: "subject", link: "/admin/subjects-curriculum" },

    { name: "Live Classes", icon: "video", link: "/admin/academics/live-classes", module: "live_classes" },

    { name: "Assessments & Gradebooks", icon: "grading", link: "admin/assessments-gradebooks",
      children: [
        { name: "Assessments", link: "/admin/academics/assessments", module: "assessments" },
        { name: "Question Bank", link: "/admin/academics/question-bank", module: "assessments" },
        { name: "Worksheets", link: "/admin/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/admin/academics/portfolio", module: "portfolio" },
        { name: "Gradebook", link: "/admin/academics/gradebook", module: "assessments" },
      ]
    },

    { name: "Reports & Report Cards", icon: "insights", link: "admin/reports",
      children: [
        { name: "Insights", link: "/admin/academics/insights" },
        { name: "Analytics", link: "/admin/academics/analytics", module: "analytics" },
        { name: "School Report", link: "/admin/academics/school-report", module: "analytics" },
        { name: "Report Cards", link: "/admin/academics/report-cards", module: "analytics" },
      ]
    },

    { name: "Interventions", icon: "monitoring", link: "/admin/academics/interventions", module: "interventions" },

    { name: "Resources", icon: "library_books", link: "admin/resources",
      children: [
        { name: "Resources", link: "/admin/academics/resources" },
        { name: "Resource Viewer", link: "/admin/academics/resource-viewer" },
      ]
    },

    { name: "Communication", icon: "forum", link: "admin/communication",
      children: [
        { name: "Messages", link: "/admin/communication/messages" },
        { name: "Announcements", link: "/admin/communication/announcements" },
      ]
    },

    { name: "Calendar", icon: "calendar_month", link: "/admin/calendar" },

    { name: "Settings & Permissions", icon: "settings", link: "admin/management",
      children: [
        { name: "School Profile", link: "/admin/management/school-profile" },
        { name: "Settings", link: "/admin/management/settings" },
        { name: "Billing", link: "/admin/management/billing" },
        { name: "Safeguarding", link: "/admin/management/safeguarding", module: "safeguarding" },
        { name: "Support", link: "/admin/management/support" },
      ]
    },
  ]

  tutor_admin: IMenu[]= [
    { name: "Main", icon: "dashboard", link: "academy/main",
      children: [
        { name: "Dashboard", link: "/academy/main" },
        { name: "Calendar", link: "/academy/calendar" },
      ]
    },

    { name: "Students", icon: "local_library", link: "/academy/students/" },

    { name: "Tutor", icon: "supervisor_account", link: "academy/teachers",
      children: [
        { name: "Tutors", link: "/academy/tutors/" },
        { name: "New Tutor", link: "/academy/tutors/new" }
      ]
    },

    { name: "Academics", icon: "school", link: "/academy/academics",
      children: [
        { name: "Classes & Learners", link: "/academy/academics/classes-learners" },
        { name: "Subjects", link: "/academy/academics/subjects" },
        { name: "Lesson Content", link: "/academy/academics/lesson-content" },
        { name: "Delivery Pack", link: "/academy/academics/delivery-pack" },
        { name: "Scheme of Work", link: "/academy/academics/scheme-of-work" },
        { name: "Scheme Coverage", link: "/academy/academics/scheme-coverage" },
        { name: "Curriculum Map", link: "/academy/academics/curriculum-map" },
        { name: "Approval Queue", link: "/academy/academics/approval-queue" },
        { name: "Question Bank", link: "/academy/academics/question-bank", module: "assessments" },
        { name: "Assessments", link: "/academy/academics/assessments", module: "assessments" },
        { name: "Worksheets", link: "/academy/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/academy/academics/portfolio", module: "portfolio" },
        { name: "Live Classes", link: "/academy/academics/live-classes", module: "live_classes" },
        { name: "Gradebook", link: "/academy/academics/gradebook", module: "assessments" },
        { name: "Insights", link: "/academy/academics/insights" },
        { name: "Analytics", link: "/academy/academics/analytics", module: "analytics" },
        { name: "Reports & Report Cards", link: "/academy/academics/school-report", module: "analytics" },
        { name: "Report Cards", link: "/academy/academics/report-cards", module: "analytics" },
        { name: "Interventions", link: "/academy/academics/interventions", module: "interventions" },
        { name: "Resources", link: "/academy/academics/resources" },
        { name: "Resource Viewer", link: "/academy/academics/resource-viewer" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "academy/management",
      children: [
        { name: "Academy Profile", link: "/academy/management/academy-profile" },
        { name: "Settings", link: "/academy/management/settings" },
        { name: "Billing", link: "/academy/management/billing" },
        { name: "Safeguarding", link: "/academy/management/safeguarding", module: "safeguarding" },
        { name: "Support", link: "/academy/management/support" },
      ]
    },

    { name: "Communication", icon: "forum", link: "academy/communication",
      children: [
        { name: "Messages", link: "/academy/communication/messages" },
        { name: "Announcements", link: "/academy/communication/announcements" },
      ]
    },
  ]


  super_admin: IMenu[] = [
    { name: "Main", icon: "dashboard", link: "/super-admin/main",
      children: [
        { name: "Dashboard", link: "/super-admin/main" },
      ]
    },

    { name: "Analytics", icon: "insights", link: "/super-admin/management/analytics",
      children: [
        { name: "Platform Analytics", link: "/super-admin/management/analytics" },
        { name: "Reports", link: "/super-admin/management/reports" },
        { name: "Safeguarding", link: "/super-admin/management/safeguarding" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "/super-admin/management",
      children: [
        { name: "Institutions", link: "/super-admin/management/institutions" },
        { name: "Subjects", link: "/super-admin/management/subjects" },
        { name: "Subscription Plans", link: "/super-admin/management/plans" },
        { name: "Billing", link: "/super-admin/management/billing" },
        { name: "Content Library", link: "/super-admin/management/content-library" },
        { name: "Content Packages", link: "/super-admin/management/content-packages" },
        { name: "Users & Roles", link: "/super-admin/management/users-roles" },
        { name: "Support Centre", link: "/super-admin/management/support" },
        { name: "Audit Logs", link: "/super-admin/management/audit-logs" },
        { name: "System Settings", link: "/super-admin/management/system-settings" },
      ]
    },
  ]


  // Compact, consolidated teacher sidebar, mirroring the admin consolidation
  // (no reference PNG for teacher; this is the parallel taxonomy). Every
  // existing page stays reachable; no routes change in this phase.
  teacher: IMenu[] = [
    { name: "Dashboard", icon: "dashboard", link: "/teacher/main" },

    { name: "My Classes", icon: "group", link: "teacher/my-classes",
      children: [
        { name: "Classes", link: "/teacher/classes" },
        { name: "Learners", link: "/teacher/main/students" },
      ]
    },

    { name: "Subjects & Curriculum", icon: "subject", link: "teacher/subjects-curriculum",
      children: [
        { name: "Topics", link: "/teacher/academics/topics" },
        { name: "Lesson Content", link: "/teacher/academics/lesson-content" },
        { name: "Delivery Pack", link: "/teacher/academics/delivery-pack" },
        { name: "Scheme of Work", link: "/teacher/academics/scheme-of-work" },
        { name: "Scheme Coverage", link: "/teacher/academics/scheme-coverage" },
        { name: "Curriculum Map", link: "/teacher/academics/curriculum-map" },
        { name: "Approval Queue", link: "/teacher/academics/approval-queue" },
      ]
    },

    { name: "Assessments & Gradebooks", icon: "grading", link: "teacher/assessments-gradebooks",
      children: [
        { name: "Assessments", link: "/teacher/academics/assessments", module: "assessments" },
        { name: "Question Bank", link: "/teacher/academics/question-bank", module: "assessments" },
        { name: "Worksheets", link: "/teacher/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/teacher/academics/portfolio", module: "portfolio" },
        { name: "Assignments & Submissions", link: "/teacher/academics/submissions" },
        { name: "Gradebook", link: "/teacher/academics/gradebook", module: "assessments" },
      ]
    },

    { name: "Live Classes", icon: "video", link: "/teacher/academics/live-classes", module: "live_classes" },

    { name: "Feedback & Questions", icon: "rate_review", link: "teacher/feedback-questions",
      children: [
        { name: "Give Feedback", link: "/teacher/feedback" },
        { name: "Tutor Questions", link: "/teacher/ask-tutor" },
      ]
    },

    { name: "Reports & Analytics", icon: "insights", link: "teacher/reports",
      children: [
        { name: "Insights", link: "/teacher/academics/insights" },
        { name: "Analytics", link: "/teacher/academics/analytics", module: "analytics" },
        { name: "School Report", link: "/teacher/academics/school-report", module: "analytics" },
        { name: "Report Cards", link: "/teacher/academics/report-cards", module: "analytics" },
      ]
    },

    { name: "Interventions", icon: "monitoring", link: "/teacher/academics/interventions", module: "interventions" },

    { name: "Safeguarding", icon: "health_and_safety", link: "/teacher/academics/safeguarding", module: "safeguarding" },

    { name: "Resources", icon: "library_books", link: "teacher/resources",
      children: [
        { name: "Resources", link: "/teacher/academics/resources" },
        { name: "Resource Viewer", link: "/teacher/academics/resource-viewer" },
      ]
    },

    { name: "Communication", icon: "forum", link: "teacher/communication",
      children: [
        { name: "Messages", link: "/teacher/communication/messages" },
        { name: "Announcements", link: "/teacher/communication/announcements" },
      ]
    },

    { name: "Settings", icon: "settings", link: "teacher/settings-group",
      children: [
        { name: "Profile", link: "/teacher/management/profile" },
        { name: "Settings", link: "/teacher/settings" },
      ]
    },
  ]

  // Flat learner sidebar, no group categories; every item is a direct icon + link.
  student = [
    { name: "Dashboard", icon: "dashboard", link: "/student/main" },
    // Learn merged into My Subjects (the /learn lesson-player route is kept for deep-links).
    { name: "My Subjects", icon: "subject", link: "/student/academics/my-subjects" },
    // Assessments + Worksheets merged into one tabbed page (each tab self-gates on its module).
    { name: "Coursework", icon: "assignment", link: "/student/academics/assessments-worksheets" },
    { name: "Portfolio", icon: "folder_special", link: "/student/academics/portfolio", module: "portfolio" },
    { name: "Ask Tutor", icon: "forum", link: "/student/academics/ask-tutor" },
    { name: "Live Classes", icon: "video", link: "/student/academics/live-classes", module: "live_classes" },
    { name: "Resources", icon: "library_books", link: "/student/academics/resources" },
    // Progress Report + Feedback merged into one tabbed page (Progress tab self-gates on analytics).
    { name: "Progress", icon: "insights", link: "/student/academics/progress-feedback" },
    { name: "Report a Concern", icon: "health_and_safety", link: "/student/report-concern" },
    // Settings access lives on the Profile page now (its /student/settings route is kept).
    { name: "Profile", icon: "person", link: "/student/management/profile" },
  ]

  parent = [
    { name: "Main", icon: "dashboard", link: "parent/main",
      children: [
        { name: "Dashboard", link: "/parent/main" },
      ]
    },

    { name: "Academics", icon: "school", link: "/parent/academics",
      children: [
        { name: "Progress Report", link: "/parent/academics/progress-report", module: "analytics" },
      ]
    },

    { name: "Communication", icon: "forum", link: "parent/communication",
      children: [
        { name: "Messages", link: "/parent/communication/messages" },
        { name: "Announcements", link: "/parent/communication/announcements" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "parent/management",
      children: [
        { name: "Profile", link: "/parent/management/profile" },
      ]
    },
  ]

}
