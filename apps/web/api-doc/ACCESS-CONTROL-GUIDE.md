# Content Access Control - Implementation Guide

## 📖 Overview

Your LMS platform now has **subscription-based content access control** that automatically filters learning materials based on what users have access to through their subscriptions.

---

## 🎯 How It Works

### Access Levels

The system supports three access levels:

1. **Free Users (No Subscription)**
   - Can access ONLY free content (where `is_premium = false`)
   - Cannot see any premium learning materials

2. **Subscribed Users (With Packages)**
   - Can access FREE content + PREMIUM content from their assigned packages
   - Content is filtered based on active subscription status

3. **Subscribed Users (No Packages)**
   - Can access ONLY free content
   - Same as free users until packages are assigned

---

## 🔌 API Endpoints

### 1. Get My Accessible Content

**Endpoint:** `GET /backend/content/my-content`

**Who can use it:** Any logged-in user (student, teacher, parent, admin)

**What it does:**
- Automatically checks user's active subscriptions
- Finds all content packages assigned to those subscriptions
- Returns content user has access to (free + premium from packages)
- Supports filtering by subject, grade level, content type

**Query Parameters:**
```
?subjectArea=math          // Filter by subject (optional)
?gradeLevel=10             // Filter by grade level (optional)
?contentType=video         // Filter by content type (optional)
```

**Example Request:**
```bash
GET /backend/content/my-content?subjectArea=math&contentType=video
Authorization: Bearer eyJ0eXAiOiJKV1Qi...
```

**Example Response:**
```json
[
  {
    "id": 15,
    "title": "Quadratic Equations",
    "description": "Learn to solve quadratic equations",
    "content_type": "video",
    "file_url": "/backend/uploads/content-library/quadratic_video.mp4",
    "subject_area": "math",
    "grade_level": "10",
    "difficulty_level": "intermediate",
    "tags": ["algebra", "equations", "grade 10"],
    "is_premium": true,
    "is_active": true,
    "first_name": "John",
    "last_name": "Doe"
  },
  {
    "id": 3,
    "title": "Basic Arithmetic",
    "description": "Free introduction to arithmetic",
    "content_type": "document",
    "file_url": "/backend/uploads/content-library/arithmetic.pdf",
    "subject_area": "math",
    "grade_level": "all",
    "difficulty_level": "beginner",
    "tags": ["math", "basics"],
    "is_premium": false,
    "is_active": true,
    "first_name": "Jane",
    "last_name": "Smith"
  }
]
```

---

### 2. Check Access to Specific Content

**Endpoint:** `GET /backend/content/check-access?contentId=:id`

**Who can use it:** Any logged-in user

**What it does:**
- Checks if user can access a specific content item
- Returns `true` or `false` with reason
- Useful before showing "Download" or "View" buttons

**Example Request:**
```bash
GET /backend/content/check-access?contentId=15
Authorization: Bearer eyJ0eXAiOiJKV1Qi...
```

**Example Responses:**

**User has access via subscription:**
```json
{
  "hasAccess": true,
  "reason": "subscription"
}
```

**Content is free:**
```json
{
  "hasAccess": true,
  "reason": "free_content"
}
```

**User does NOT have access:**
```json
{
  "hasAccess": false,
  "reason": "no_subscription"
}
```

---

## 🔄 Access Control Flow

### Backend Logic (Automatic)

When a user requests `/content/my-content`, the backend automatically:

```
1. Authenticate user from JWT token
   ↓
2. Query subscriptions table:
   SELECT id FROM subscriptions 
   WHERE user_id = ? 
   AND is_active = true 
   AND (end_date IS NULL OR end_date >= CURRENT_DATE)
   ↓
3. If NO subscriptions found:
   → Return only FREE content (is_premium = false)
   → EXIT
   ↓
4. If subscriptions exist:
   Query subscription_packages table:
   SELECT DISTINCT package_id 
   FROM subscription_packages 
   WHERE subscription_id IN (...)
   ↓
5. If NO packages found:
   → Return only FREE content
   → EXIT
   ↓
6. If packages exist:
   Query package_content table:
   SELECT DISTINCT content_id 
   FROM package_content 
   WHERE package_id IN (...)
   ↓
7. Return content where:
   - is_premium = false (FREE), OR
   - id IN (content IDs from packages)
```

---

## 📊 Database Tables Involved

### Subscriptions Flow

```
users
  ↓ (user_id)
subscriptions (user has subscriptions)
  ↓ (subscription_id)
subscription_packages (subscriptions linked to packages)
  ↓ (package_id)
package_content (packages contain content)
  ↓ (content_id)
content_library (the actual learning materials)
```

### Key Fields

**subscriptions:**
- `user_id` - Which user owns this subscription
- `is_active` - Is subscription currently active?
- `end_date` - When does subscription expire?

**subscription_packages:**
- `subscription_id` - Which subscription
- `package_id` - Which content package is included

**package_content:**
- `package_id` - Which package
- `content_id` - Which content item is included

**content_library:**
- `is_premium` - True = requires subscription, False = free for all
- `is_active` - Only active content is shown

---

## 💡 Use Cases

### Use Case 1: Student Browsing Content

**Scenario:** Student logs in and wants to see available learning materials

**Frontend Code (Example):**
```typescript
// student-content.component.ts
loadContent() {
  this.api.get<ContentItem[]>('content/my-content').subscribe({
    next: (content) => {
      this.availableContent = content;
      // User sees only what they have access to
    },
    error: (err) => console.error('Error loading content', err)
  });
}

// Filter by subject
loadMathVideos() {
  this.api.get<ContentItem[]>('content/my-content?subjectArea=math&contentType=video')
    .subscribe(content => this.mathVideos = content);
}
```

**Backend Response:**
- If student has "Math Pack" subscription → Shows all math content + free content
- If student has no subscription → Shows only free content

---

### Use Case 2: Checking Before Download

**Scenario:** User clicks "Download" button - check access first

**Frontend Code (Example):**
```typescript
// Before allowing download
downloadContent(contentId: number) {
  this.api.get<AccessCheck>(`content/check-access?contentId=${contentId}`)
    .subscribe({
      next: (result) => {
        if (result.hasAccess) {
          // Allow download
          window.open(`/backend/uploads/content-library/${contentId}`, '_blank');
        } else {
          // Show upgrade prompt
          this.showSubscriptionPrompt();
        }
      }
    });
}
```

---

### Use Case 3: Conditional UI Display

**Scenario:** Show "Locked" badge on premium content user can't access

**Frontend Code (Example):**
```typescript
// content-card.component.ts
ngOnInit() {
  if (this.content.is_premium) {
    this.checkAccess(this.content.id);
  } else {
    this.hasAccess = true; // Free content
  }
}

checkAccess(contentId: number) {
  this.api.get<AccessCheck>(`content/check-access?contentId=${contentId}`)
    .subscribe(result => {
      this.hasAccess = result.hasAccess;
      this.showLockIcon = !result.hasAccess;
    });
}
```

**HTML Template:**
```html
<div class="content-card">
  <h3>{{ content.title }}</h3>
  <span *ngIf="showLockIcon" class="lock-badge">🔒 Premium</span>
  <button 
    *ngIf="hasAccess" 
    (click)="viewContent(content.id)">
    View Content
  </button>
  <button 
    *ngIf="!hasAccess" 
    (click)="showUpgradePrompt()">
    Unlock with Subscription
  </button>
</div>
```

---

## 🔐 Security Features

### 1. Automatic Filtering
- No manual checks needed
- Backend automatically filters based on subscription
- Users CANNOT access premium content without subscription

### 2. Subscription Validation
- Checks `is_active` flag
- Validates `end_date` (must be NULL or >= today)
- Expired subscriptions = no premium access

### 3. No Privilege Escalation
- Users can only see their own subscriptions
- Cannot request content for other users
- JWT token validates identity

### 4. Database Indexes
- `subscription_packages(subscription_id)` - Indexed ✅
- `package_content(package_id)` - Indexed ✅
- Queries are fast even with thousands of content items

---

## 📈 Testing Scenarios

### Scenario 1: Free User
```bash
# User with NO subscriptions
GET /backend/content/my-content
→ Returns: Only content where is_premium = false

GET /backend/content/check-access?contentId=5 (premium content)
→ {"hasAccess": false, "reason": "no_subscription"}
```

### Scenario 2: User with Math Subscription
```bash
# User has subscription with "Math Pack" assigned
GET /backend/content/my-content
→ Returns: Free content + Math premium content

GET /backend/content/my-content?subjectArea=science
→ Returns: Only free science content (no science subscription)

GET /backend/content/check-access?contentId=8 (math content)
→ {"hasAccess": true, "reason": "subscription"}
```

### Scenario 3: Expired Subscription
```bash
# User had subscription but end_date has passed
GET /backend/content/my-content
→ Returns: Only free content (subscription not counted as active)
```

### Scenario 4: Inactive Subscription
```bash
# User subscription exists but is_active = false
GET /backend/content/my-content
→ Returns: Only free content
```

---

## 🎨 Frontend Integration Recommendations

### Student Dashboard

**Recommended Layout:**
1. **My Learning Library** - Shows accessible content via `/content/my-content`
2. **Filter Options** - Subject, Grade, Content Type
3. **Content Cards** - Display title, description, premium badge
4. **Access Indicators** - Lock icon for inaccessible premium content

**Services to Create:**
```typescript
// content.service.ts
export class ContentService {
  constructor(private http: HttpClient) {}

  getMyContent(filters?: ContentFilters): Observable<ContentItem[]> {
    let params = new HttpParams();
    if (filters?.subjectArea) params = params.set('subjectArea', filters.subjectArea);
    if (filters?.gradeLevel) params = params.set('gradeLevel', filters.gradeLevel);
    if (filters?.contentType) params = params.set('contentType', filters.contentType);
    
    return this.http.get<ContentItem[]>('/content/my-content', { params });
  }

  checkAccess(contentId: number): Observable<AccessCheck> {
    return this.http.get<AccessCheck>(`/content/check-access?contentId=${contentId}`);
  }
}
```

---

## ✅ Implementation Checklist

Backend (Complete):
- [x] Created `/content/my-content` endpoint
- [x] Created `/content/check-access` endpoint
- [x] Subscription validation (active + not expired)
- [x] Free content always accessible
- [x] Premium content filtered by packages
- [x] Database indexes for performance
- [x] Security review passed

Frontend (Next Steps):
- [ ] Create student content library component
- [ ] Add content filtering UI (subject, grade, type)
- [ ] Display content cards with access indicators
- [ ] Implement "locked" badge for premium content
- [ ] Add subscription upgrade prompts
- [ ] Test with different subscription scenarios

---

## 🚀 Quick Start Example

**1. Create some free content (Super Admin):**
```bash
POST /backend/content/library
(upload a document, set isPremium=false)
```

**2. Create premium content and package:**
```bash
POST /backend/content/library (isPremium=true)
POST /backend/content/packages (bundle content IDs)
```

**3. Assign package to user subscription:**
```bash
POST /backend/content/assign
{
  "subscriptionId": 5,
  "packageIds": [1]
}
```

**4. Student fetches accessible content:**
```bash
GET /backend/content/my-content
→ Sees free content + premium content from package 1
```

---

## 📞 API Reference Summary

| Endpoint | Method | Access | Purpose |
|----------|--------|--------|---------|
| `/content/my-content` | GET | Any user | Get accessible content based on subscriptions |
| `/content/check-access` | GET | Any user | Check if user can access specific content |
| `/content/library` | GET/POST/PUT/DELETE | Super Admin | Manage global content library |
| `/content/packages` | GET/POST/PUT/DELETE | Super Admin | Manage content packages |
| `/content/assign` | POST | Admins | Assign packages to subscriptions |

---

## 🎯 Summary

Your platform now has **intelligent content filtering** that:
- ✅ Automatically shows users only what they have access to
- ✅ Validates subscription status and expiration
- ✅ Always provides free content to everyone
- ✅ Filters premium content based on assigned packages
- ✅ Performs efficiently with proper database indexes
- ✅ Provides clear access indicators for UI

Students can now browse learning materials, and the backend ensures they only see content they're allowed to access based on their active subscriptions!

**Next Step:** Build the frontend UI to consume these endpoints and create a beautiful learning library experience for students.
