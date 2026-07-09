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

  school_admin: IMenu[]= [
    { name: "Main", icon: "dashboard", link: "admin/main",
      children: [
        { name: "Dashboard", link: "/admin/main" },
      ]
    },

    { name: "Student", icon: "local_library", link: "admin/students",
      children: [
        { name: "Students", link: "/admin/students/" },
        { name: "Enrollment", link: "/admin/enrollments" },
        { name: "New Student", link: "/admin/students/new" }
      ]
    },

    { name: "Teacher", icon: "supervisor_account", link: "admin/teachers",
      children: [
        { name: "Teachers", link: "/admin/teachers/" },
        { name: "New Teacher", link: "/admin/teachers/new" }
      ]
    },

    { name: "Academics", icon: "school", link: "/admin/academics",
      children: [
        { name: "Class", link: "/admin/academics/classes" },
        { name: "Subjects", link: "/admin/academics/subjects" },
        { name: "Topics", link: "/admin/academics/topics" },
        { name: "Lesson Content", link: "/admin/academics/lesson-content" },
        { name: "Scheme of Work", link: "/admin/academics/scheme-of-work" },
        { name: "Question Bank", link: "/admin/academics/question-bank", module: "assessments" },
        { name: "Assessments", link: "/admin/academics/assessments", module: "assessments" },
        { name: "Worksheets", link: "/admin/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/admin/academics/portfolio", module: "portfolio" },
        { name: "Live Classes", link: "/admin/academics/live-classes", module: "live_classes" },
        { name: "Gradebook", link: "/admin/academics/gradebook", module: "assessments" },
        { name: "Insights", link: "/admin/academics/insights" },
        { name: "Analytics", link: "/admin/academics/analytics", module: "analytics" },
        { name: "Interventions", link: "/admin/academics/interventions", module: "interventions" },
        { name: "Resources", link: "/admin/academics/resources" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "admin/management",
      children: [
        { name: "School Profile", link: "/admin/management/school-profile" },
        { name: "Settings", link: "/admin/management/settings" },
        { name: "Billing", link: "/admin/management/billing" },
        { name: "Safeguarding", link: "/admin/management/safeguarding", module: "safeguarding" },
        { name: "Support", link: "/admin/management/support" },
      ]
    },

    { name: "Communication", icon: "forum", link: "admin/communication",
      children: [
        { name: "Messages", link: "/admin/communication/messages" },
        { name: "Announcements", link: "/admin/communication/announcements" },
      ]
    },
  ]

  tutor_admin: IMenu[]= [
    { name: "Main", icon: "dashboard", link: "academy/main",
      children: [
        { name: "Dashboard", link: "/academy/main" },
      ]
    },

    { name: "Student", icon: "local_library", link: "academy/students",
      children: [
        { name: "Students", link: "/academy/students/" },
        { name: "Enrollment", link: "/academy/enrollments" },
        { name: "New Student", link: "/academy/students/new" }
      ]
    },

    { name: "Tutor", icon: "supervisor_account", link: "academy/teachers",
      children: [
        { name: "Tutors", link: "/academy/tutors/" },
        { name: "New Tutor", link: "/academy/tutors/new" }
      ]
    },

    { name: "Academics", icon: "school", link: "/academy/academics",
      children: [
        { name: "Class", link: "/academy/academics/classes" },
        { name: "Subjects", link: "/academy/academics/subjects" },
        { name: "Topics", link: "/academy/academics/topics" },
        { name: "Lesson Content", link: "/academy/academics/lesson-content" },
        { name: "Scheme of Work", link: "/academy/academics/scheme-of-work" },
        { name: "Question Bank", link: "/academy/academics/question-bank", module: "assessments" },
        { name: "Assessments", link: "/academy/academics/assessments", module: "assessments" },
        { name: "Worksheets", link: "/academy/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/academy/academics/portfolio", module: "portfolio" },
        { name: "Live Classes", link: "/academy/academics/live-classes", module: "live_classes" },
        { name: "Gradebook", link: "/academy/academics/gradebook", module: "assessments" },
        { name: "Insights", link: "/academy/academics/insights" },
        { name: "Analytics", link: "/academy/academics/analytics", module: "analytics" },
        { name: "Interventions", link: "/academy/academics/interventions", module: "interventions" },
        { name: "Resources", link: "/academy/academics/resources" },
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
    { name: "Main", icon: "dashboard", link: "super-admin/main",
      children: [
        { name: "Dashboard", link: "/super-admin/main" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "super-admin/management",
      children: [
        { name: "Institutions", link: "management/institutions" },
        { name: "Subjects", link: "management/subjects" },
        { name: "Subscription Plans", link: "management/plans" },
        { name: "Content Library", link: "management/content-library" },
        { name: "Content Packages", link: "management/content-packages" },
        { name: "Support Centre", link: "management/support" },
      ]
    },
  ]


  teacher = [
    { name: "Main", icon: "dashboard", link: "teacher/main",
      children: [
        { name: "Dashboard", link: "/teacher/main" },
        { name: "Student", link: "/teacher/main/students"},
      ]
    },

    { name: "Academics", icon: "school", link: "/teacher/academics",
      children: [
        { name: "Topics", link: "/teacher/academics/topics" },
        { name: "Lesson Content", link: "/teacher/academics/lesson-content" },
        { name: "Scheme of Work", link: "/teacher/academics/scheme-of-work" },
        { name: "Question Bank", link: "/teacher/academics/question-bank", module: "assessments" },
        { name: "Assessments", link: "/teacher/academics/assessments", module: "assessments" },
        { name: "Worksheets", link: "/teacher/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/teacher/academics/portfolio", module: "portfolio" },
        { name: "Live Classes", link: "/teacher/academics/live-classes", module: "live_classes" },
        { name: "Gradebook", link: "/teacher/academics/gradebook", module: "assessments" },
        { name: "Insights", link: "/teacher/academics/insights" },
        { name: "Analytics", link: "/teacher/academics/analytics", module: "analytics" },
        { name: "Interventions", link: "/teacher/academics/interventions", module: "interventions" },
        { name: "Safeguarding", link: "/teacher/academics/safeguarding", module: "safeguarding" },
        { name: "Resources", link: "/teacher/academics/resources" },
      ]
    },

    { name: "Communication", icon: "forum", link: "teacher/communication",
      children: [
        { name: "Messages", link: "/teacher/communication/messages" },
        { name: "Announcements", link: "/teacher/communication/announcements" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "teacher/management",
      children: [
        { name: "Profile", link: "/teacher/management/profile" },
      ]
    },
  ]

  student = [
    { name: "Main", icon: "dashboard", link: "student/main",
      children: [
        { name: "Dashboard", link: "/student/main" },
      ]
    },

    { name: "Academics", icon: "school", link: "/student/academics",
      children: [
        { name: "Learn", link: "/student/academics/learn" },
        { name: "Assessments", link: "/student/academics/assessments", module: "assessments" },
        { name: "Worksheets", link: "/student/academics/worksheets", module: "worksheets" },
        { name: "Portfolio", link: "/student/academics/portfolio", module: "portfolio" },
        { name: "Feedback", link: "/student/academics/feedback" },
        { name: "Live Classes", link: "/student/academics/live-classes", module: "live_classes" },
        { name: "Resources", link: "/student/academics/resources" },
        { name: "Progress Report", link: "/student/academics/progress-report", module: "analytics" },
      ]
    },

    { name: "Communication", icon: "forum", link: "student/communication",
      children: [
        { name: "Messages", link: "/student/communication/messages" },
        { name: "Announcements", link: "/student/communication/announcements" },
        { name: "Report a Concern", link: "/student/report-concern" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "student/management",
      children: [
        { name: "Profile", link: "/student/management/profile" },
      ]
    },
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
