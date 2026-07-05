import { Injectable } from '@angular/core';

export interface IMenu {
  name: string;
  icon?: string;
  link: string;
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
        { name: "Messenger", link: "/admin/main/message" },
        { name: "File Management", link: "/admin/main/file-manager" },
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
        { name: "Class Routine", link: "/admin/academics/class-routine" },
        { name: "E-Library", link: "/admin/academics/e-library" },
        // { name: "Lesson Plans", link: "/admin/academics/lesson-plans" },
        { name: "Class", link: "/admin/academics/classes" },
        { name: "Subjects", link: "/admin/academics/subjects" },
        { name: "Topics", link: "/admin/academics/topics" },
        { name: "Question Bank", link: "/admin/academics/question-bank" },
        { name: "Assessments", link: "/admin/academics/assessments" },
        { name: "Worksheets", link: "/admin/academics/worksheets" },
        { name: "Portfolio", link: "/admin/academics/portfolio" },
        { name: "Insights", link: "/admin/academics/insights" },
        { name: "Live Classes", link: "/admin/academics/live-classes" },
        { name: "Analytics", link: "/admin/academics/analytics" },
        { name: "Gradebook", link: "/admin/academics/gradebook" },
        { name: "Result", link: "/admin/academics/results" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "admin/management",
      children: [
        { name: "School Profile", link: "/admin/management/school-profile" },
        { name: "Payment", link: "/admin/management/payment" },
        { name: "Fees", link: "/admin/management/fees" },
      ]
    },
  ]

  tutor_admin: IMenu[]= [
    { name: "Main", icon: "dashboard", link: "academy/main",
      children: [
        { name: "Dashboard", link: "/academy/main" },
        { name: "Messenger", link: "/academy/main/message" },
        { name: "File Management", link: "/academy/main/file-manager" },
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
        { name: "Tutorial Routine", link: "/academy/academics/class-routine" },
        // { name: "E-Library", link: "/academy/academics/e-library" },
        // { name: "Lesson Plans", link: "/academy/academics/lesson-plans" },
        { name: "Class", link: "/academy/academics/classes" },
        { name: "Subjects", link: "/academy/academics/subjects" },
        { name: "Topics", link: "/academy/academics/topics" },
        { name: "Question Bank", link: "/academy/academics/question-bank" },
        { name: "Assessments", link: "/academy/academics/assessments" },
        { name: "Worksheets", link: "/academy/academics/worksheets" },
        { name: "Portfolio", link: "/academy/academics/portfolio" },
        { name: "Insights", link: "/academy/academics/insights" },
        { name: "Live Classes", link: "/academy/academics/live-classes" },
        { name: "Analytics", link: "/academy/academics/analytics" },
        { name: "Gradebook", link: "/academy/academics/gradebook" },
        { name: "Result", link: "/academy/academics/results" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "academy/management",
      children: [
        { name: "Academy Profile", link: "/academy/management/academy-profile" },
        { name: "Payment", link: "/academy/management/payment" },
        { name: "Subscription", link: "/academy/management/subscription" },

      ]
    },
  ]


  super_admin: IMenu[] = [
    { name: "Main", icon: "dashboard", link: "super-admin/main",
      children: [
        { name: "Dashboard", link: "/super-admin/main" },
        { name: "Messenger", link: "/super-admin/main/message" },
        { name: "File Management", link: "/super-admin/main/file-manager" }
      ]
    },

    { name: "Management", icon: "tenancy", link: "super-admin/management",
      children: [
        { name: "Institutions", link: "management/institutions" },
        { name: "Content Library", link: "management/content-library" },
        { name: "Content Packages", link: "management/content-packages" },
      ]
    },
  ]


  teacher = [
    { name: "Main", icon: "dashboard", link: "teacher/main",
      children: [
        { name: "Dashboard", link: "/teacher/main" },
        { name: "Messenger", link: "/teacher/main/message" },
        // { name: "Notification", link: "/teacher/main/notification"},
        // { name: "School Events", link: "/teacher/main/events"},
        { name: "Student", link: "/teacher/main/students"},
        // { name: "File Management", link: "/teacher/main/file-manager" },
      ]
    },

    { name: "Academics", icon: "school", link: "/teacher/academics",
      children: [
        // { name: "Pending Task", link: "/teacher/academics/pending-task" },
        // { name: "Assignments", link: "/teacher/academics/assignments" },
        { name: "Teacher Routine", link: "/teacher/academics/class-routine" },
        // { name: "Attendance", link: "/teacher/academics/attendance" },
        { name: "E-Library", link: "/teacher/academics/e-library" },
        { name: "Topics", link: "/teacher/academics/topics" },
        { name: "Question Bank", link: "/teacher/academics/question-bank" },
        { name: "Assessments", link: "/teacher/academics/assessments" },
        { name: "Worksheets", link: "/teacher/academics/worksheets" },
        { name: "Portfolio", link: "/teacher/academics/portfolio" },
        { name: "Insights", link: "/teacher/academics/insights" },
        { name: "Live Classes", link: "/teacher/academics/live-classes" },
        { name: "Analytics", link: "/teacher/academics/analytics" },
        { name: "Gradebook", link: "/teacher/academics/gradebook" },
        // { name: "Lesson Plans", link: "/teacher/academics/lesson-plans" },
        // { name: "Assessments", link: "/teacher/academics/assessments" },
        // { name: "Virtual Class", link: "/teacher/academics/virtual-class" },
        { name: "Grades", link: "/teacher/academics/results" },
        // { name: "Promotion", link: "/teacher/academics/promotion" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "teacher/management",
      children: [
        { name: "Profile", link: "/teacher/management/profile" },
        // { name: "Payment", link: "/teacher/management/payment" },
      ]
    },
  ]

  student = [
    { name: "Main", icon: "dashboard", link: "student/main",
      children: [
        { name: "Dashboard", link: "/student/main" },
        { name: "Messenger", link: "/student/main/message" },
        // { name: "Notification", link: "/student/main/notification"},
        // { name: "School Events", link: "/student/main/events"},
        // { name: "Classmates", link: "/student/main/classmate"},
        // { name: "File Management", link: "/student/main/file-manager" },
      ]
    },

    { name: "Academics", icon: "school", link: "/student/academics",
      children: [
        // { name: "Assignments", link: "/student/academics/assignments" },
        { name: "Student Routine", link: "/student/academics/class-routine" },
        { name: "E-Library", link: "/student/academics/e-library" },
        // { name: "Lesson Plans", link: "/student/academics/lesson-plans" },
        { name: "Assessments", link: "/student/academics/assessments" },
        { name: "Worksheets", link: "/student/academics/worksheets" },
        { name: "Portfolio", link: "/student/academics/portfolio" },
        { name: "Feedback", link: "/student/academics/feedback" },
        { name: "Live Classes", link: "/student/academics/live-classes" },
        { name: "Progress Report", link: "/student/academics/progress-report" },
        { name: "Performance", link: "/student/academics/performance" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "student/management",
      children: [
        { name: "Profile", link: "/student/management/profile" },
        { name: "Payment", link: "/student/management/payment" },
        { name: "Fee", link: "/student/management/fee" },
      ]
    },
  ]

  parent = [
    { name: "Main", icon: "dashboard", link: "student/main",
      children: [
        { name: "Dashboard", link: "/student/main" },
        { name: "School Events", link: "/student/main/events"},
        // { name: "File Management", link: "/student/main/file-manager" },
      ]
    },

    { name: "Academics", icon: "school", link: "/student/academics",
      children: [
        { name: "Progress Report", link: "/student/academics/progress-report" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "student/management",
      children: [
        { name: "Profile", link: "/student/management/profile" },
        // { name: "Payment", link: "/student/management/payment" },
      ]
    },
  ]

}
