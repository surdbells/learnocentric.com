# SaaS Features - Super Admin Content & Onboarding

This document describes the new SaaS features for managing institutions, content libraries, and subscriptions at a platform level.

## 📋 Overview

The LMS platform now includes comprehensive SaaS features that allow **Super Admins** to:

1. **Onboard New Institutions** - Create schools or tutoring academies with admin users in one request
2. **Manage Global Content Library** - Upload learning content once, share across all institutions
3. **Create Content Packages** - Bundle content by subject, grade level, or class type
4. **Sell Content via Subscriptions** - Link packages to subscription plans (bulk, per-subject, per-class)

---

## 🎯 Key Concepts

### 1. **Global Content Library**

Unlike institution-specific learning resources, the **Content Library** is managed by Super Admin and can be shared across ALL institutions.

**Features:**
- Upload once, share everywhere
- Categorize by subject area, grade level, difficulty
- Tag content for easy search/filtering
- Mark as premium (requires subscription) or free
- Support multiple content types: documents, videos, assignments, quizzes, interactive

### 2. **Content Packages**

Content Packages are **bundles of learning content** that can be sold to institutions or individual users.

**Package Types:**
- `full_access` - Complete access to all content
- `subject_pack` - Subject-specific content (e.g., "Grade 10 Math Complete")
- `class_pack` - Class-specific content (e.g., "Physics 101")
- `grade_level_pack` - Grade-specific content (e.g., "All Grade 5 Content")

### 3. **Subscription-Package Linking**

When a user subscribes (via `/tutoring/subscriptions`), packages can be assigned to their subscription, granting access to all content in those packages.

---

## 🗄️ Database Schema

### New Tables Created

#### `content_library`
Global content repository managed by super admin.

```sql
CREATE TABLE content_library (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    content_type VARCHAR(50), -- 'document', 'video', 'assignment', 'quiz', 'interactive'
    file_url TEXT NOT NULL,
    subject_area VARCHAR(100), -- 'math', 'science', 'english', etc.
    grade_level VARCHAR(50), -- '1', '2', '10', 'all'
    difficulty_level VARCHAR(50), -- 'beginner', 'intermediate', 'advanced'
    tags TEXT[], -- Search tags
    is_premium BOOLEAN DEFAULT true,
    is_active BOOLEAN DEFAULT true,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### `content_packages`
Bundles of content that can be sold.

```sql
CREATE TABLE content_packages (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    package_type VARCHAR(50), -- 'full_access', 'subject_pack', 'class_pack', 'grade_level_pack'
    subject_area VARCHAR(100), -- NULL for full access
    grade_level VARCHAR(50), -- NULL for full access
    class_name VARCHAR(100), -- NULL unless class-specific
    price DECIMAL(10, 2) DEFAULT 0.00,
    duration_months INTEGER DEFAULT 12,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### `package_content`
Links content to packages (many-to-many junction table).

```sql
CREATE TABLE package_content (
    id SERIAL PRIMARY KEY,
    package_id INTEGER REFERENCES content_packages(id),
    content_id INTEGER REFERENCES content_library(id),
    display_order INTEGER DEFAULT 0,
    created_at TIMESTAMP
);
```

#### `subscription_packages`
Links subscriptions to packages (grants user access to package content).

```sql
CREATE TABLE subscription_packages (
    id SERIAL PRIMARY KEY,
    subscription_id INTEGER REFERENCES subscriptions(id),
    package_id INTEGER REFERENCES content_packages(id),
    granted_at TIMESTAMP
);
```

---

## 🔌 API Endpoints

### Super Admin Onboarding

#### `POST /admin/onboard`
Create a new institution (school/tutoring academy) with admin user in one request.

**Access:** `super_admin` only

**Request Body:**
```json
{
  "institutionName": "Elite Math Academy",
  "institutionType": "tutoring",
  "adminEmail": "admin@elitemath.com",
  "adminPassword": "securePassword123",
  "adminFirstName": "John",
  "adminLastName": "Doe",
  "address": "456 Learning St",
  "phone": "+1234567890",
  "email": "contact@elitemath.com",
  "website": "https://elitemath.com",
  "logoUrl": "https://example.com/logo.png",
  "primaryColor": "#3B82F6",
  "tagline": "Master Math with Confidence",
  "packageIds": [1, 2, 3]
}
```

**Required Fields:**
- `institutionName`
- `adminEmail`
- `adminPassword`
- `adminFirstName`
- `adminLastName`

**Optional Fields:**
- `institutionType` (defaults to 'school')
- `packageIds` - Array of package IDs to assign to the new institution
- All branding fields (address, phone, logoUrl, primaryColor, etc.)

**Response:**
```json
{
  "success": true,
  "institutionId": 5,
  "adminUserId": 23,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "message": "Institution and admin user created successfully"
}
```

**What Happens:**
1. Creates new institution in database
2. Creates admin user (`school_admin` or `tutor_admin` based on type)
3. Optionally creates subscription and assigns content packages
4. Returns JWT token for the new admin

---

#### `GET /admin/institutions`
List all institutions (super admin view).

**Access:** `super_admin` only

**Query Parameters:**
- `type` (optional) - Filter by institution type: 'school' or 'tutoring'

**Response:**
```json
[
  {
    "id": 1,
    "name": "Demo School",
    "type": "school",
    "address": "123 Main St",
    "is_active": true,
    "user_count": 45,
    "created_at": "2025-01-01T10:00:00Z"
  }
]
```

---

#### `GET /admin/stats`
Get platform-wide statistics.

**Access:** `super_admin` only

**Response:**
```json
{
  "totalInstitutions": 12,
  "totalSchools": 8,
  "totalTutoringAcademies": 4,
  "totalUsers": 567,
  "totalSubscriptions": 234,
  "totalContentLibraryItems": 156,
  "totalContentPackages": 24,
  "activeUsers": 489
}
```

---

### Global Content Library Management

#### `GET /content/library`
List all content in the global library.

**Access:** `super_admin` only

**Query Parameters:**
- `subjectArea` (optional) - Filter by subject (e.g., 'math', 'science')
- `gradeLevel` (optional) - Filter by grade (e.g., '10', 'all')
- `contentType` (optional) - Filter by type (e.g., 'video', 'document')
- `isActive` (optional) - Filter by active status ('true' or 'false')

**Response:**
```json
[
  {
    "id": 1,
    "title": "Introduction to Algebra",
    "description": "Basic algebraic concepts for Grade 8",
    "content_type": "video",
    "file_url": "/backend/uploads/content-library/algebra_intro.mp4",
    "subject_area": "math",
    "grade_level": "8",
    "difficulty_level": "beginner",
    "tags": ["algebra", "basics", "grade8"],
    "is_premium": true,
    "is_active": true,
    "created_at": "2025-01-15T10:00:00Z"
  }
]
```

---

#### `GET /content/library/:id`
Get single content item by ID.

**Access:** `super_admin` only

**Response:**
```json
{
  "id": 1,
  "title": "Introduction to Algebra",
  "description": "Basic algebraic concepts for Grade 8",
  "content_type": "video",
  "file_url": "/backend/uploads/content-library/algebra_intro.mp4",
  "file_type": "video/mp4",
  "file_size": 15728640,
  "subject_area": "math",
  "grade_level": "8",
  "difficulty_level": "beginner",
  "tags": ["algebra", "basics", "grade8"],
  "is_premium": true,
  "is_active": true,
  "first_name": "Admin",
  "last_name": "User"
}
```

---

#### `POST /content/library`
Upload new content to global library.

**Access:** `super_admin` only

**Content-Type:** `multipart/form-data`

**Form Fields:**
- `file` (required) - The content file to upload
- `title` (required) - Content title
- `description` - Content description
- `contentType` - Type: 'document', 'video', 'assignment', 'quiz', 'interactive'
- `subjectArea` - Subject: 'math', 'science', 'english', etc.
- `gradeLevel` - Grade level: '1', '2', '10', 'all'
- `difficultyLevel` - Difficulty: 'beginner', 'intermediate', 'advanced'
- `tags` - Comma-separated tags (e.g., "algebra,basics,grade8")
- `isPremium` - Boolean: true or false (default: true)

**Response:**
```json
{
  "success": true,
  "id": 45,
  "fileUrl": "/backend/uploads/content-library/1738456789_algebra.mp4"
}
```

---

#### `PUT /content/library`
Update content metadata.

**Access:** `super_admin` only

**Request Body (all fields except id are optional):**
```json
{
  "id": 1,
  "title": "Updated Title",
  "description": "Updated description",
  "subjectArea": "math",
  "gradeLevel": "9",
  "difficultyLevel": "intermediate",
  "tags": ["algebra", "advanced"],
  "isPremium": true,
  "isActive": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Content updated successfully"
}
```

---

#### `DELETE /content/library?id=:id`
Soft delete content (sets is_active = false).

**Access:** `super_admin` only

**Response:**
```json
{
  "success": true,
  "message": "Content deleted successfully"
}
```

---

### Content Packages Management

#### `GET /content/packages`
List all content packages.

**Access:** `super_admin` only

**Query Parameters:**
- `packageType` (optional) - Filter by type: 'full_access', 'subject_pack', 'class_pack', 'grade_level_pack'

**Response:**
```json
[
  {
    "id": 1,
    "name": "Grade 10 Math Complete",
    "description": "Complete math curriculum for grade 10",
    "package_type": "subject_pack",
    "subject_area": "math",
    "grade_level": "10",
    "class_name": null,
    "price": 199.00,
    "duration_months": 12,
    "is_active": true,
    "content_count": 45,
    "created_at": "2025-01-01T10:00:00Z"
  }
]
```

---

#### `GET /content/packages/:id`
Get single package with all included content.

**Access:** `super_admin` only

**Response:**
```json
{
  "id": 1,
  "name": "Grade 10 Math Complete",
  "description": "Complete math curriculum for grade 10",
  "package_type": "subject_pack",
  "subject_area": "math",
  "grade_level": "10",
  "price": 199.00,
  "duration_months": 12,
  "is_active": true,
  "content_count": 45,
  "content": [
    {
      "id": 12,
      "title": "Algebra Basics",
      "content_type": "video",
      "file_url": "/backend/uploads/content-library/algebra.mp4",
      "display_order": 0
    }
  ]
}
```

---

#### `POST /content/packages`
Create new content package.

**Access:** `super_admin` only

**Request Body:**
```json
{
  "name": "Physics Subject Pack",
  "description": "Complete physics curriculum",
  "packageType": "subject_pack",
  "subjectArea": "physics",
  "gradeLevel": "11",
  "price": 149.00,
  "durationMonths": 12,
  "contentIds": [1, 2, 3, 4, 5]
}
```

**Required Fields:**
- `name`
- `packageType`

**Optional Fields:**
- `description`
- `subjectArea` - Required for 'subject_pack'
- `gradeLevel` - Required for 'grade_level_pack'
- `className` - Required for 'class_pack'
- `price` (default: 0.00)
- `durationMonths` (default: 12)
- `contentIds` - Array of content IDs to include in package

**Response:**
```json
{
  "success": true,
  "packageId": 7
}
```

---

#### `PUT /content/packages`
Update package metadata and/or content.

**Access:** `super_admin` only

**Request Body (all fields except id are optional):**
```json
{
  "id": 1,
  "name": "Updated Package Name",
  "description": "Updated description",
  "price": 249.00,
  "durationMonths": 18,
  "isActive": true,
  "contentIds": [1, 2, 3, 5, 6, 7]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Package updated successfully"
}
```

---

#### `DELETE /content/packages?id=:id`
Delete a content package (hard delete).

**Access:** `super_admin` only

**Response:**
```json
{
  "success": true,
  "message": "Package deleted successfully"
}
```

---

### Subscription-Package Linking

#### `POST /content/assign`
Assign content packages to a subscription.

**Access:** `super_admin`, `tutor_admin`, `school_admin`

**Request Body:**
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
  "message": "Packages assigned to subscription"
}
```

**Authorization:**
- `super_admin` can assign to any subscription
- Admins can only assign to subscriptions in their own institution

---

### Content Access Control (User Endpoints)

#### `GET /content/my-content`
Get all content accessible to the current user based on their active subscriptions.

**Access:** Any authenticated user

**Query Parameters:**
- `subjectArea` (optional) - Filter by subject (e.g., 'math', 'science')
- `gradeLevel` (optional) - Filter by grade (e.g., '10', 'all')
- `contentType` (optional) - Filter by type (e.g., 'video', 'document')

**Access Logic:**
1. If user has NO active subscriptions → returns only FREE content
2. If user has subscriptions with NO packages → returns only FREE content
3. If user has subscriptions WITH packages → returns FREE content + PREMIUM content from packages

**Response:**
```json
[
  {
    "id": 1,
    "title": "Introduction to Algebra",
    "description": "Basic algebraic concepts",
    "content_type": "video",
    "file_url": "/backend/uploads/content-library/algebra.mp4",
    "subject_area": "math",
    "grade_level": "8",
    "difficulty_level": "beginner",
    "tags": ["algebra", "basics"],
    "is_premium": true,
    "is_active": true,
    "first_name": "Admin",
    "last_name": "User"
  }
]
```

**Example Usage:**
```bash
# Get all accessible content
GET /backend/content/my-content

# Get only math content
GET /backend/content/my-content?subjectArea=math

# Get grade 10 videos
GET /backend/content/my-content?gradeLevel=10&contentType=video
```

---

#### `GET /content/check-access?contentId=:id`
Check if the current user has access to specific content.

**Access:** Any authenticated user

**Query Parameters:**
- `contentId` (required) - ID of the content to check

**Response:**
```json
{
  "hasAccess": true,
  "reason": "subscription"
}
```

**Possible Reasons:**
- `free_content` - Content is free, accessible to everyone
- `subscription` - User has active subscription with this content
- `no_subscription` - User does NOT have access (premium content, no subscription)

**Example Usage:**
```bash
# Check access to content ID 5
GET /backend/content/check-access?contentId=5
```

**Use Case:** Before displaying a "View" or "Download" button, check if user has access

---

## 💡 Usage Examples

### Example 1: Onboard a New Tutoring Academy

**Step 1:** Super admin creates institution + admin user
```bash
POST /backend/admin/onboard
{
  "institutionName": "Elite Math Academy",
  "institutionType": "tutoring",
  "adminEmail": "admin@elitemath.com",
  "adminPassword": "SecurePass123!",
  "adminFirstName": "Sarah",
  "adminLastName": "Johnson",
  "logoUrl": "https://example.com/elite-math-logo.png",
  "primaryColor": "#10B981",
  "tagline": "Excel in Mathematics",
  "packageIds": [1, 2, 3]
}
```

**Result:**
- New institution created
- Admin user created with `tutor_admin` role
- Subscription created with access to packages 1, 2, 3
- Admin receives JWT token to access their dashboard

---

### Example 2: Create and Sell a Content Package

**Step 1:** Upload content to library
```bash
POST /backend/content/library
(multipart/form-data)
file: grade10_algebra.pdf
title: "Algebra for Grade 10"
contentType: "document"
subjectArea: "math"
gradeLevel: "10"
isPremium: true
```

**Step 2:** Create a package
```bash
POST /backend/content/packages
{
  "name": "Grade 10 Math Complete Pack",
  "packageType": "subject_pack",
  "subjectArea": "math",
  "gradeLevel": "10",
  "price": 199.00,
  "durationMonths": 12,
  "contentIds": [45, 46, 47, 48]
}
```

**Step 3:** Assign package to user's subscription
```bash
POST /backend/content/assign
{
  "subscriptionId": 12,
  "packageIds": [7]
}
```

**Result:**
- User with subscription ID 12 now has access to all content in package 7

---

### Example 3: Create Subject-Specific Subscriptions

**Scenario:** Tutoring academy wants to offer "Math Only" subscriptions.

**Step 1:** Create Math content package
```bash
POST /backend/content/packages
{
  "name": "Complete Math Curriculum (All Grades)",
  "packageType": "subject_pack",
  "subjectArea": "math",
  "price": 299.00,
  "durationMonths": 12,
  "contentIds": [1, 2, 3, 4, 5, ..., 50]
}
```

**Step 2:** Create subscription for student
```bash
POST /backend/tutoring/subscriptions
{
  "userId": 25,
  "subscriptionType": "per_subject",
  "startDate": "2025-01-01",
  "endDate": "2025-12-31",
  "price": 299.00
}
```

**Step 3:** Assign Math package to subscription
```bash
POST /backend/content/assign
{
  "subscriptionId": 15,
  "packageIds": [8]
}
```

**Result:**
- Student has "Math Only" subscription with access to all math content across all grades

---

### Example 4: Student Accessing Learning Content

**Scenario:** Student wants to browse learning materials

**Step 1:** Student logs in and fetches their accessible content
```bash
GET /backend/content/my-content
Authorization: Bearer [student_token]
```

**Backend Logic:**
1. Checks student's active subscriptions
2. Gets packages assigned to those subscriptions
3. Gets content IDs from those packages
4. Returns FREE content + PREMIUM content from packages

**Step 2:** Student filters for math videos
```bash
GET /backend/content/my-content?subjectArea=math&contentType=video
```

**Step 3:** Before downloading, frontend checks access
```bash
GET /backend/content/check-access?contentId=15
```

**Response:**
```json
{
  "hasAccess": true,
  "reason": "subscription"
}
```

**Result:**
- Student sees only content they have access to
- Can filter by subject, grade, type
- No premium content shown unless they have subscription

---

### Example 5: Access Control Flow

**Free User (No Subscription):**
```bash
GET /backend/content/my-content
→ Returns: Only free content (is_premium = false)
```

**Subscribed User (With Packages):**
```bash
GET /backend/content/my-content
→ Returns: Free content + Premium content from assigned packages
```

**Premium Content Check:**
```bash
GET /backend/content/check-access?contentId=25
→ Free user: {"hasAccess": false, "reason": "no_subscription"}
→ Subscribed user: {"hasAccess": true, "reason": "subscription"}
→ Free content: {"hasAccess": true, "reason": "free_content"}
```

---

## 🎨 Frontend Integration (To Be Implemented)

### Super Admin Dashboard

**Recommended Pages:**
1. **Dashboard** - Platform-wide statistics
2. **Institutions** - List/create/manage all institutions
3. **Content Library** - Upload/manage global content
4. **Content Packages** - Create/manage content packages
5. **Subscriptions** - View all platform subscriptions

### Integration Points

**API Service (TypeScript):**
```typescript
// admin.service.ts
onboardInstitution(data: OnboardRequest) {
  return this.http.post('/admin/onboard', data);
}

getStats() {
  return this.http.get('/admin/stats');
}

// content.service.ts
uploadContent(formData: FormData) {
  return this.http.post('/content/library', formData);
}

createPackage(packageData: ContentPackage) {
  return this.http.post('/content/packages', packageData);
}

assignPackages(subscriptionId: number, packageIds: number[]) {
  return this.http.post('/content/assign', { subscriptionId, packageIds });
}
```

---

## ✅ Current Implementation Status

| Feature | Status | Notes |
|---------|--------|-------|
| Database Schema | ✅ Complete | All tables created with indexes |
| Onboarding API | ✅ Complete | Single-request institution + admin creation |
| Content Library API | ✅ Complete | Full CRUD for global content |
| Content Packages API | ✅ Complete | Full CRUD with content linking |
| Package Assignment | ✅ Complete | Link packages to subscriptions |
| Content Access Control | ✅ Complete | User endpoints filter content by subscription packages |
| Super Admin Frontend | ⏳ Pending | Next phase |

---

## 📋 Complete Endpoint Reference

### Super Admin Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| POST | `/admin/onboard` | Create new institution with admin user | super_admin |
| GET | `/admin/institutions` | List all institutions | super_admin |
| GET | `/admin/stats` | Get platform statistics | super_admin |

### Content Library Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/content/library` | List all content with filters | super_admin |
| GET | `/content/library/:id` | Get single content item | super_admin |
| POST | `/content/library` | Upload new content (multipart) | super_admin |
| PUT | `/content/library` | Update content metadata | super_admin |
| DELETE | `/content/library?id=:id` | Soft delete content | super_admin |

### Content Packages Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/content/packages` | List all packages with filters | super_admin |
| GET | `/content/packages/:id` | Get package with content list | super_admin |
| POST | `/content/packages` | Create new package | super_admin |
| PUT | `/content/packages` | Update package and content | super_admin |
| DELETE | `/content/packages?id=:id` | Delete package | super_admin |

### Package Assignment Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| POST | `/content/assign` | Assign packages to subscription | super_admin, tutor_admin, school_admin |

### User Content Access Endpoints

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/content/my-content` | Get accessible content (auto-filtered by subscription) | Any authenticated user |
| GET | `/content/check-access?contentId=:id` | Check if user can access specific content | Any authenticated user |

---

## 🔐 Security Notes

1. **All endpoints require authentication** via JWT token
2. **Role-based access control**: Most endpoints are `super_admin` only
3. **Institution scoping**: Admins can only manage their own institution's subscriptions
4. **File upload security**: Files are stored in `/backend/uploads/content-library/`
5. **SQL injection protection**: All queries use parameterized statements

---

## 📊 Sample Data Flow

```
Super Admin
    └─> Creates Content Library Items (videos, documents, quizzes)
    └─> Bundles into Content Packages (Math Pack, Science Pack, Full Access)
    └─> Onboards New Institution (School or Tutoring Academy)
        └─> Creates Admin User automatically
        └─> Optionally assigns default packages
    
Tutor/School Admin
    └─> Creates Subscriptions for Students
    └─> Assigns Content Packages to Subscriptions
    
Student/Parent
    └─> Accesses Content via Active Subscription
    └─> Content filtered by assigned packages
```

---

## 🚀 Next Steps

1. **Build Super Admin Dashboard UI** - Angular components for content/package management
2. **Student Content Library UI** - Interface to browse and access learning materials via `/content/my-content`
3. **Add Content Preview** - Allow users to preview before subscribing
4. **Payment Integration** - Connect Stripe for automated subscription payments
5. **Analytics Dashboard** - Track content usage, popular packages, revenue

---

## 📞 Support

For questions or issues with the SaaS features, refer to:
- **API Documentation**: `API-DOCUMENTATION.md`
- **Database Schema**: Check `SAAS-FEATURES.md` (this file)
- **Postman Collection**: Import and test all endpoints

All endpoints are now live at `http://localhost:8000/backend/`
