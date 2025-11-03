# LMS Platform - Complete API Documentation

**Version:** 5.0.1  
**Base URL:** `http://localhost:8000/backend` (Development)  
**Authentication:** Bearer Token (JWT)

This document provides complete reference for all API endpoints with request/response examples.

---

## 📑 Table of Contents

1. [Authentication](#authentication)
2. [School Management](#school-management)
3. [Timetable Management](#timetable-management)
4. [Payments & Fees](#payments--fees)
5. [Reports](#reports)
6. [Email Communications](#email-communications)
7. [Learning Resources](#learning-resources)
8. [Tutoring Academy](#tutoring-academy)
9. [Teacher Endpoints](#teacher-endpoints)
10. [Student Endpoints](#student-endpoints)
11. [Parent Endpoints](#parent-endpoints)
12. [Super Admin Endpoints](#super-admin-endpoints)
13. [Content Library](#content-library-saas)
14. [Content Packages](#content-packages-saas)
15. [Package Assignment](#package-assignment)
16. [User Content Access](#user-content-access)

---

## Authentication

All endpoints (except login/register) require a JWT token:
```
Authorization: Bearer <your_jwt_token>
```

### POST /auth/login
**Request:**
```json
{
  "email": "admin@demoschool.com",
  "password": "password123"
}
```
**Response (200) - Admin/Teacher:**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "email": "admin@demoschool.com",
    "role": "school_admin",
    "firstName": "John",
    "lastName": "Doe",
    "institutionId": 1
  }
}
```

**Response (200) - Student (includes class info):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 5,
    "email": "student@example.com",
    "role": "student",
    "firstName": "Jane",
    "lastName": "Smith",
    "institutionId": 1,
    "classId": 3,
    "className": "Grade 10-A",
    "gradeLevel": "10",
    "section": "A"
  }
}
```
> **Note:** For students, the login response includes current class enrollment information (classId, className, gradeLevel, section) from their most recent enrollment.

### POST /auth/register
**Request:**
```json
{
  "email": "newuser@example.com",
  "password": "SecurePass123",
  "firstName": "Jane",
  "lastName": "Smith",
  "role": "student",
  "institutionId": 1
}
```
**Response (200):**
```json
{
  "success": true,
  "userId": 15,
  "token": "eyJ0eXAiOiJKV1Qi..."
}
```

### GET /auth/me
Returns current user information.

### PUT /auth/profile
**Request:**
```json
{
  "firstName": "John",
  "lastName": "Updated",
  "phone": "+1234567890",
  "address": "456 New Street"
}
```

---

## School Management

### Enrollments

**GET /school/enrollments** - List all enrollments  
**GET /school/enrollments/:id** - Get single enrollment  
**POST /school/enrollments** - Create enrollment  
**PUT /school/enrollments** - Update enrollment  
**DELETE /school/enrollments?id=:id** - Delete enrollment

**Example Create:**
```json
{
  "studentId": 3,
  "classId": 1,
  "enrollmentDate": "2025-01-15"
}
```

### Classes

**GET /school/classes** - List all classes  
**GET /school/classes/:id** - Get single class  
**POST /school/classes** - Create class  
**PUT /school/classes** - Update class  
**DELETE /school/classes?id=:id** - Delete class

**Example Create:**
```json
{
  "name": "Grade 10-B",
  "gradeLevel": "10",
  "section": "B",
  "academicYear": "2024-2025"
}
```

### Grades

**GET /school/grades?studentId=:id** - List grades  
**GET /school/grades/:id** - Get single grade  
**POST /school/grades** - Create grade  
**PUT /school/grades** - Update grade  
**DELETE /school/grades?id=:id** - Delete grade

**Example Create:**
```json
{
  "studentId": 3,
  "classId": 1,
  "subjectId": 1,
  "marksObtained": 85,
  "totalMarks": 100,
  "examName": "Final Exam",
  "examDate": "2025-06-15",
  "remarks": "Excellent work"
}
```

### Subjects

**GET /school/subjects** - List subjects  
**GET /school/subjects/:id** - Get single subject  
**POST /school/subjects** - Create subject  
**PUT /school/subjects** - Update subject  
**DELETE /school/subjects?id=:id** - Delete subject

### Students & Teachers

**GET /school/students** - List all students  
**PUT /school/students** - Update student  
**GET /school/teachers** - List all teachers

---

## Timetable Management

**GET /timetable/periods?classId=:id** - Get periods for class  
**POST /timetable/periods** - Create period  
**PUT /timetable/periods** - Update period  
**DELETE /timetable/periods?id=:id** - Delete period

**Example Create:**
```json
{
  "classId": 1,
  "subjectId": 1,
  "teacherId": 2,
  "dayOfWeek": 1,
  "startTime": "09:00",
  "endTime": "10:00",
  "room": "101"
}
```

---

## Payments & Fees

### Payments

**GET /payments/list?userId=:id** - List payments  
**GET /payments/:id** - Get single payment  
**POST /payments/create** - Create payment  
**POST /payments/confirm** - Confirm payment

**Example Create:**
```json
{
  "userId": 3,
  "feeStructureId": 1,
  "amount": 1000.00,
  "paymentMethod": "stripe"
}
```

**Example Confirm:**
```json
{
  "paymentId": 5,
  "transactionId": "txn_67890"
}
```

### Fee Structures

**GET /payments/fee-structures** - List fee structures  
**POST /payments/fee-structures** - Create fee structure  
**PUT /payments/fee-structures** - Update fee structure  
**DELETE /payments/fee-structures?id=:id** - Delete fee structure

---

## Reports

**POST /reports/generate** - Generate report card  
**GET /reports/student?studentId=:id** - Get student classes

**Example Generate:**
```json
{
  "studentId": 3,
  "classId": 1,
  "examTerm": "Final"
}
```

**Response:**
```json
{
  "student": {
    "id": 3,
    "first_name": "Alice",
    "class_name": "Grade 10-A"
  },
  "grades": [
    {
      "subject_name": "Mathematics",
      "marks_obtained": 85,
      "total_marks": 100,
      "percentage": 85.0,
      "grade": "A"
    }
  ],
  "overall": {
    "percentage": 85.0,
    "grade": "A"
  }
}
```

---

## Email Communications

**POST /emails/send** - Send email  
**GET /emails/history** - Get email history

**Example Send:**
```json
{
  "recipientEmail": "student@example.com",
  "subject": "Important Announcement",
  "body": "Please check your grades."
}
```

---

## Learning Resources

**GET /storage/resources?classId=:id&subjectId=:id** - List resources  
**GET /storage/resources/:id** - Get single resource  
**POST /storage/resources** - Upload resource (multipart/form-data)

**Upload Fields:**
- file: (binary)
- title: "Math Chapter 1"
- description: "Introduction"
- classId: "1"
- subjectId: "1"

---

## Tutoring Academy

### Institutions

**GET /tutoring/institutions** - List tutoring academies  
**POST /tutoring/institutions** - Create academy  
**PUT /tutoring/institutions** - Update academy

**Example Create:**
```json
{
  "name": "Elite Tutoring",
  "logoUrl": "https://example.com/logo.png",
  "primaryColor": "#8b5cf6",
  "email": "info@elite.com",
  "phone": "555-0123"
}
```

### Subscriptions

**GET /tutoring/subscriptions?userId=:id** - List subscriptions  
**GET /tutoring/subscriptions/:id** - Get single subscription  
**POST /tutoring/subscriptions** - Create subscription  
**PUT /tutoring/subscriptions** - Update subscription  
**DELETE /tutoring/subscriptions?id=:id** - Delete subscription

**Example Create:**
```json
{
  "userId": 3,
  "subscriptionType": "per_user",
  "amount": 99.99,
  "startDate": "2025-01-01",
  "endDate": "2025-12-31"
}
```

---

## Teacher Endpoints

**GET /teacher/timetable/:teacherId** - Get teacher's timetable  
**GET /teacher/classes/:teacherId** - Get teacher's classes  
**GET /teacher/students/:teacherId?classId=:id** - Get teacher's students

---

## Student Endpoints

**GET /student/courses/:studentId** - Get student's courses  
**GET /student/grades/:studentId?classId=:id** - Get student's grades  
**GET /student/timetable/:studentId** - Get student's timetable

---

## Parent Endpoints

**GET /parent/children/:parentId** - Get parent's children  
**GET /parent/child-grades?studentId=:id** - Get child's grades

---

## Super Admin Endpoints

### POST /admin/onboard
**Onboard new institution with admin user**

**Request:**
```json
{
  "institutionName": "ABC High School",
  "institutionType": "school",
  "adminEmail": "admin@abchigh.edu",
  "adminPassword": "SecurePass123",
  "adminFirstName": "John",
  "adminLastName": "Smith",
  "address": "123 Education Ave",
  "phone": "+1234567890",
  "logoUrl": "https://example.com/logo.png",
  "primaryColor": "#3B82F6",
  "packageIds": [1, 2]
}
```

**Response (200):**
```json
{
  "success": true,
  "institutionId": 5,
  "adminUserId": 20,
  "token": "eyJ0eXAi...",
  "message": "Institution and admin user created successfully"
}
```

### GET /admin/institutions
**List all institutions**

Query params: `?type=school` (optional)

**Response:**
```json
[
  {
    "id": 1,
    "name": "Demo School",
    "type": "school",
    "is_active": true,
    "user_count": 150
  }
]
```

### GET /admin/stats
**Get platform statistics**

**Response:**
```json
{
  "totalInstitutions": 10,
  "totalSchools": 7,
  "totalTutoringAcademies": 3,
  "totalUsers": 1250,
  "totalSubscriptions": 450,
  "totalContentLibraryItems": 320,
  "totalContentPackages": 15,
  "activeUsers": 1180
}
```

---

## Content Library (SaaS)

**Access:** super_admin only

### GET /content/library
**List all content**

Query params:
- `subjectArea` (optional)
- `gradeLevel` (optional)
- `contentType` (optional)
- `isActive` (optional)

**Response:**
```json
[
  {
    "id": 1,
    "title": "Quadratic Equations Tutorial",
    "description": "Complete guide",
    "content_type": "video",
    "file_url": "/backend/uploads/content-library/video_123.mp4",
    "subject_area": "math",
    "grade_level": "10",
    "difficulty_level": "intermediate",
    "tags": ["algebra", "equations"],
    "is_premium": true,
    "is_active": true,
    "first_name": "Super",
    "last_name": "Admin"
  }
]
```

### GET /content/library/:id
**Get single content item**

### POST /content/library
**Upload new content (multipart/form-data)**

**Fields:**
- file: (binary)
- title: "Quadratic Equations Tutorial"
- description: "Complete guide"
- contentType: "video"
- subjectArea: "math"
- gradeLevel: "10"
- difficultyLevel: "intermediate"
- tags: "algebra,equations,grade 10"
- isPremium: "true"

**Response:**
```json
{
  "success": true,
  "id": 15,
  "fileUrl": "/backend/uploads/content-library/1730000000_video.mp4"
}
```

### PUT /content/library
**Update content metadata**

**Request:**
```json
{
  "id": 1,
  "title": "Updated Title",
  "description": "Updated description",
  "tags": ["algebra", "advanced math"],
  "isPremium": false,
  "isActive": true
}
```

### DELETE /content/library?id=:id
**Soft delete content**

---

## Content Packages (SaaS)

**Access:** super_admin only

### GET /content/packages
**List all packages**

Query params: `?packageType=subject_specific` (optional)

**Response:**
```json
[
  {
    "id": 1,
    "name": "Math Premium Pack",
    "description": "Complete math content for grade 10",
    "package_type": "subject_specific",
    "subject_area": "math",
    "grade_level": "10",
    "price": 99.99,
    "duration_months": 12,
    "is_active": true,
    "content_count": 25
  }
]
```

### GET /content/packages/:id
**Get single package with content list**

**Response:**
```json
{
  "id": 1,
  "name": "Math Premium Pack",
  "content": [
    {
      "id": 1,
      "title": "Quadratic Equations Tutorial",
      "content_type": "video",
      "display_order": 0
    }
  ],
  "content_count": 1
}
```

### POST /content/packages
**Create new package**

**Request:**
```json
{
  "name": "Math Premium Pack",
  "description": "Complete math content for grade 10",
  "packageType": "subject_specific",
  "subjectArea": "math",
  "gradeLevel": "10",
  "price": 99.99,
  "durationMonths": 12,
  "contentIds": [1, 2, 3, 5, 8]
}
```

### PUT /content/packages
**Update package**

**Request:**
```json
{
  "id": 1,
  "name": "Updated Package Name",
  "price": 149.99,
  "contentIds": [1, 2, 3, 5, 8, 10, 12]
}
```

### DELETE /content/packages?id=:id
**Delete package**

---

## Package Assignment

### POST /content/assign
**Assign packages to subscription**

**Access:** super_admin, tutor_admin, school_admin

**Request:**
```json
{
  "subscriptionId": 5,
  "packageIds": [1, 2, 3]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Packages assigned successfully",
  "assignedCount": 3
}
```

---

## User Content Access

**Access:** Any authenticated user

### GET /content/my-content
**Get accessible content based on user's subscriptions**

Query params (all optional):
- `subjectArea` - Filter by subject
- `gradeLevel` - Filter by grade
- `contentType` - Filter by type

**Response (with subscription):**
```json
[
  {
    "id": 1,
    "title": "Quadratic Equations Tutorial",
    "content_type": "video",
    "file_url": "/backend/uploads/content-library/video_123.mp4",
    "subject_area": "math",
    "grade_level": "10",
    "tags": ["algebra", "equations"],
    "is_premium": true,
    "is_active": true
  },
  {
    "id": 3,
    "title": "Basic Arithmetic",
    "is_premium": false
  }
]
```

**Response (without subscription - free content only):**
```json
[
  {
    "id": 3,
    "title": "Basic Arithmetic",
    "is_premium": false
  }
]
```

### GET /content/check-access?contentId=:id
**Check if user can access specific content**

**Response (has access via subscription):**
```json
{
  "hasAccess": true,
  "reason": "subscription"
}
```

**Response (free content):**
```json
{
  "hasAccess": true,
  "reason": "free_content"
}
```

**Response (no access):**
```json
{
  "hasAccess": false,
  "reason": "no_subscription"
}
```

---

## Access Control Logic

**User Content Access Flow:**

1. **No Subscription:**
   - Returns: Only FREE content (is_premium = false)

2. **Active Subscription with Packages:**
   - Returns: FREE content + PREMIUM content from assigned packages

3. **Active Subscription without Packages:**
   - Returns: Only FREE content

4. **Expired Subscription:**
   - Treated as "No Subscription"
   - Returns: Only FREE content

**Subscription Validation:**
- Must have `is_active = true`
- Must have `end_date IS NULL` OR `end_date >= CURRENT_DATE`

---

## Error Responses

### 400 Bad Request
```json
{
  "error": "Descriptive error message"
}
```

### 401 Unauthorized
```json
{
  "error": "Invalid or missing authentication token"
}
```

### 403 Forbidden
```json
{
  "error": "You don't have permission to access this resource"
}
```

### 404 Not Found
```json
{
  "error": "Resource not found"
}
```

### 500 Internal Server Error
```json
{
  "error": "An unexpected error occurred"
}
```

---

## Security Features

1. **Role-Based Access Control (RBAC)**
   - All endpoints enforce role checks
   - JWT token contains role claim

2. **Institution Scoping**
   - All queries filtered by institution_id
   - Prevents cross-institution data leakage

3. **Ownership Validation**
   - Users can only access their own data
   - Teachers: Only assigned classes
   - Students: Only own grades
   - Parents: Only children's data

4. **Subscription-Based Access**
   - Premium content requires active subscription
   - Automatic expiration handling
   - Package-based content filtering

---

## Role-Based Access Control

The system supports six user roles: `super_admin`, `school_admin`, `tutor_admin`, `teacher`, `student`, `parent`.

### Key Endpoint Permissions

#### School Management
| Endpoint | Roles Allowed |
|----------|--------------|
| POST/PUT/DELETE /school/enrollments | `school_admin`, `super_admin` |
| **POST/PUT/DELETE /school/classes** | `school_admin`, `tutor_admin`, `super_admin` ✨ |
| POST/PUT/DELETE /school/grades | `school_admin`, `teacher`, `super_admin` |
| **POST/PUT/DELETE /school/subjects** | `school_admin`, `tutor_admin`, `super_admin` ✨ |
| **PUT /school/students** | `school_admin`, `tutor_admin`, `super_admin` ✨ |
| GET /school/* | Any authenticated user (institution-scoped) |

#### Timetable Management
| Endpoint | Roles Allowed |
|----------|--------------|
| POST/PUT/DELETE /timetable/periods | `school_admin`, `tutor_admin`, `super_admin` |
| GET /timetable/* | Any authenticated user |

#### Payments & Fees
| Endpoint | Roles Allowed |
|----------|--------------|
| POST/PUT/DELETE /payments/fee-structures | `school_admin`, `tutor_admin`, `super_admin` |
| POST /payments/create | Any authenticated user |

#### Super Admin Only
| Endpoint | Roles Allowed |
|----------|--------------|
| POST /admin/onboard | `super_admin` only |
| POST/PUT/DELETE /content/library | `super_admin` only |
| POST/PUT/DELETE /content/packages | `super_admin` only |
| GET /admin/stats | `super_admin` only |

> ✨ **Recent Update (Oct 30, 2025):** `tutor_admin` role now has full permissions to manage students, classes, and subjects, providing feature parity with `school_admin` for tutoring academies.

---

## Date & Time Formats

- **Dates:** `YYYY-MM-DD` (e.g., "2025-01-15")
- **Times:** `HH:MM` (24-hour, e.g., "09:00")
- **Timestamps:** ISO 8601 (e.g., "2025-01-15T10:00:00Z")

---

## Authentication Flow

1. **Login:** POST /auth/login → Receive JWT token
2. **Store:** Save token securely (localStorage)
3. **Use:** Include in Authorization header for all requests
4. **Expiry:** Tokens expire after 24 hours → Re-authenticate

---

## File Upload Limits

- Maximum file size: **50 MB**
- Supported formats: PDF, DOC, DOCX, MP4, MP3, PNG, JPG, etc.
- Encoding: **multipart/form-data**

---

## Version Information

**API Version:** 5.0.1  
**Release Date:** October 30, 2025  
**Features:** Complete SaaS platform with subscription-based content access control

### Changelog (v5.0.1 - October 30, 2025)
- ✨ **Enhanced Login Response:** Student login now includes current class information (classId, className, gradeLevel, section)
- ✨ **Tutor Admin Permissions:** `tutor_admin` role now has full access to manage students, classes, and subjects
- 🔧 **Bug Fixes:** Fixed authorization issues for tutoring academies
- ✅ **Verified:** Profile editing, teacher grading, and report generation all working correctly

---

## Quick Reference

### Core School Features
- ✅ Authentication & User Management
- ✅ Classes, Enrollments, Subjects
- ✅ Grades & Report Cards
- ✅ Timetable Management
- ✅ Payment Processing
- ✅ Email Communications
- ✅ Learning Resources

### Tutoring Academy Features
- ✅ White-label Branding
- ✅ Subscription Management
- ✅ Multi-institution Support

### SaaS Features
- ✅ Super Admin Onboarding
- ✅ Global Content Library
- ✅ Content Packages
- ✅ Package Assignment to Subscriptions
- ✅ **Subscription-Based Access Control** ⭐ NEW
- ✅ Platform Statistics

---

**Need Help?** Contact your system administrator for API support.
