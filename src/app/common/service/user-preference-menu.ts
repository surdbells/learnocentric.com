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
    { name: "Main", icon: "dashboard", link: "tutor/main",
      children: [
        { name: "Dashboard", link: "/tutor/main" },
        { name: "Messenger", link: "/tutor/main/message" },
        { name: "File Management", link: "/tutor/main/file-manager" },
      ]
    },

    { name: "Student", icon: "local_library", link: "tutor/students",
      children: [
        { name: "Students", link: "/tutor/students/" },
        { name: "Enrollment", link: "/tutor/enrollments" },
        { name: "New Student", link: "/tutor/students/new" }
      ]
    },

    { name: "Tutor", icon: "supervisor_account", link: "tutor/teachers",
      children: [
        { name: "Tutors", link: "/tutor/teachers/" },
        { name: "New Tutor", link: "/tutor/teachers/new" }
      ]
    },

    { name: "Academics", icon: "school", link: "/tutor/academics",
      children: [
        { name: "Tutorial Routine", link: "/tutor/academics/class-routine" },
        // { name: "E-Library", link: "/tutor/academics/e-library" },
        // { name: "Lesson Plans", link: "/tutor/academics/lesson-plans" },
        { name: "Class", link: "/tutor/academics/classes" },
        { name: "Subjects", link: "/tutor/academics/subjects" },
        { name: "Result", link: "/tutor/academics/results" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "tutor/management",
      children: [
        { name: "Academy Profile", link: "/tutor/management/school-profile" },
        { name: "Payment", link: "/tutor/management/payment" },
        { name: "Subscription", link: "/tutor/management/subscription" },

      ]
    },
  ]


  teacher = [
    { name: "Main", icon: "dashboard", link: "teacher/main",
      children: [
        { name: "Dashboard", link: "/teacher/main" },
        { name: "Messenger", link: "/teacher/main/message" },
        { name: "Notification", link: "/teacher/main/notification"},
        { name: "School Events", link: "/teacher/main/events"},
        { name: "Student", link: "/teacher/main/students"},
        // { name: "File Management", link: "/teacher/main/file-manager" },
      ]
    },

    { name: "Academics", icon: "school", link: "/teacher/academics",
      children: [
        { name: "Pending Task", link: "/teacher/academics/pending-task" },
        { name: "Assignments", link: "/teacher/academics/assignments" },
        { name: "Teacher Routine", link: "/teacher/academics/class-routine" },
        { name: "Attendance", link: "/teacher/academics/attendance" },
        { name: "E-Library", link: "/teacher/academics/e-library" },
        { name: "Lesson Plans", link: "/teacher/academics/lesson-plans" },
        { name: "Assessments", link: "/teacher/academics/assessments" },
        { name: "Virtual Class", link: "/teacher/academics/virtual-class" },
        { name: "Grades", link: "/teacher/academics/grades" },
        { name: "Promotion", link: "/teacher/academics/promotion" },
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
        { name: "Notification", link: "/student/main/notification"},
        { name: "School Events", link: "/student/main/events"},
        { name: "Classmates", link: "/student/main/classmate"},
        // { name: "File Management", link: "/student/main/file-manager" },
      ]
    },

    { name: "Academics", icon: "school", link: "/student/academics",
      children: [
        { name: "Assignments", link: "/student/academics/assignments" },
        { name: "Student Routine", link: "/student/academics/class-routine" },
        { name: "E-Library", link: "/student/academics/e-library" },
        { name: "Lesson Plans", link: "/student/academics/lesson-plans" },
        { name: "Performance", link: "/student/academics/performance" },
        { name: "Assessments", link: "/student/academics/assessments" },
      ]
    },

    { name: "Management", icon: "tenancy", link: "student/management",
      children: [
        { name: "Profile", link: "/student/management/profile" },
        // { name: "Payment", link: "/student/management/payment" },
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


    { name: "Management", icon: "tenancy", link: "student/management",
      children: [
        { name: "Profile", link: "/student/management/profile" },
        // { name: "Payment", link: "/student/management/payment" },
      ]
    },
  ]

}
