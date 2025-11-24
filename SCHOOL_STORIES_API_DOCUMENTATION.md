# School Stories API Documentation

Complete guide for the School Stories feature - think Instagram/Facebook Stories for your school!

---

## 📖 Table of Contents

1. [Overview](#overview)
2. [Story Types](#story-types)
3. [Visibility Options](#visibility-options)
4. [API Endpoints](#api-endpoints)
5. [Request/Response Examples](#requestresponse-examples)
6. [Reactions & Comments](#reactions--comments)
7. [Analytics](#analytics)
8. [Best Practices](#best-practices)

---

## Overview

The School Stories feature allows schools to share updates, photos, videos, announcements, and achievements in a social media-style format. Stories can:

-   **Expire automatically** (e.g., after 24 hours)
-   **Be pinned** for important announcements
-   **Have reactions** (like, love, celebrate, etc.)
-   **Allow comments** with replies
-   **Track views** and engagement
-   **Target specific audiences** (students, staff, parents, etc.)

---

## Story Types

| Type           | Description                | Use Case                               |
| -------------- | -------------------------- | -------------------------------------- |
| `photo`        | Image/photo story          | Daily highlights, events, activities   |
| `video`        | Video story                | Performances, tutorials, announcements |
| `text`         | Text-only story            | Quick updates, quotes, reminders       |
| `announcement` | Official announcement      | Important notifications                |
| `achievement`  | Student/school achievement | Awards, accomplishments, milestones    |
| `event`        | Event story                | Upcoming events, invitations           |

---

## Visibility Options

| Visibility       | Who Can See                                            |
| ---------------- | ------------------------------------------------------ |
| `public`         | Everyone (students, staff, parents, guardians)         |
| `students`       | Only students                                          |
| `staff`          | Only staff members                                     |
| `parents`        | Only parents                                           |
| `guardians`      | Only guardians                                         |
| `teachers`       | Only teachers                                          |
| `admin_only`     | Only administrators                                    |
| `class_specific` | Specific classes (requires `visible_to_classes` array) |

---

## API Endpoints

### Base URL

```
https://api.compasse.net/api/v1/stories
```

### Authentication

All endpoints require:

```http
Authorization: Bearer {token}
X-Subdomain: {school_subdomain}
```

---

### 1. **Get All Stories**

**Endpoint:** `GET /api/v1/stories`

**Query Parameters:**

-   `type` (optional) - Filter by type: `photo`, `video`, `text`, `announcement`, `achievement`, `event`
-   `category` (optional) - Filter by category: `sports`, `academics`, `events`, etc.
-   `visibility` (optional) - Filter by visibility
-   `pinned` (optional) - Show only pinned stories: `true`/`false`
-   `recent` (optional) - Show recent stories: `true`/`false`
-   `hours` (optional) - Hours for recent filter (default: 24)
-   `search` (optional) - Search in title/content
-   `sort_by` (optional) - Sort by field (default: `created_at`)
-   `sort_order` (optional) - Sort order: `asc`/`desc` (default: `desc`)
-   `per_page` (optional) - Items per page (default: 15)

**Example Request:**

```bash
curl -X GET "https://api.compasse.net/api/v1/stories?type=announcement&pinned=true" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

**Response (200):**

```json
{
    "stories": {
        "data": [
            {
                "id": 1,
                "school_id": 1,
                "user_id": 5,
                "title": "School Reopening Announcement",
                "content": "We are excited to welcome all students back...",
                "type": "announcement",
                "media": ["https://cdn.example.com/image1.jpg"],
                "thumbnail": "https://cdn.example.com/thumb1.jpg",
                "visibility": "public",
                "visible_to_classes": null,
                "is_pinned": true,
                "expires_at": "2025-11-25T23:59:59.000000Z",
                "is_active": true,
                "allow_comments": true,
                "allow_reactions": true,
                "views_count": 245,
                "reactions_count": 56,
                "comments_count": 12,
                "shares_count": 8,
                "tags": ["reopening", "important"],
                "category": "announcement",
                "created_at": "2025-11-23T10:00:00.000000Z",
                "updated_at": "2025-11-23T10:00:00.000000Z",
                "is_expired": false,
                "has_user_viewed": true,
                "user_reaction": "like",
                "author": {
                    "id": 5,
                    "name": "Principal John Doe",
                    "email": "principal@westwood.samschool.com",
                    "role": "principal"
                }
            }
        ],
        "current_page": 1,
        "per_page": 15,
        "total": 1
    }
}
```

---

### 2. **Get Single Story**

**Endpoint:** `GET /api/v1/stories/{story_id}`

**Note:** Automatically increments view count for authenticated users.

**Response (200):**

```json
{
  "story": {
    "id": 1,
    "title": "Basketball Tournament Winners!",
    "content": "Congratulations to our basketball team...",
    "type": "achievement",
    "media": ["https://cdn.example.com/team.jpg"],
    "views_count": 156,
    "reactions_count": 42,
    "comments_count": 8,
    "author": { ... },
    "reactions": [
      {
        "id": 1,
        "reaction_type": "celebrate",
        "user": {
          "id": 10,
          "name": "Jane Smith"
        },
        "created_at": "2025-11-23T12:00:00.000000Z"
      }
    ],
    "comments": [
      {
        "id": 1,
        "comment": "Amazing achievement!",
        "user": {
          "id": 15,
          "name": "Parent Mike"
        },
        "replies": [
          {
            "id": 2,
            "comment": "Well done!",
            "user": { ... }
          }
        ],
        "created_at": "2025-11-23T12:30:00.000000Z"
      }
    ]
  }
}
```

---

### 3. **Create Story**

**Endpoint:** `POST /api/v1/stories`

**Request Body:**

```json
{
    "title": "Sports Day 2025!",
    "content": "Join us for our annual sports day...",
    "type": "event",
    "media": [
        "https://cdn.example.com/sports-day.jpg",
        "https://cdn.example.com/sports-day-2.jpg"
    ],
    "thumbnail": "https://cdn.example.com/sports-day-thumb.jpg",
    "visibility": "public",
    "visible_to_classes": null,
    "is_pinned": false,
    "expires_at": "2025-11-30T23:59:59",
    "allow_comments": true,
    "allow_reactions": true,
    "tags": ["sports", "event", "2025"],
    "category": "events"
}
```

**Validation Rules:**

-   `title` (optional, string, max 255)
-   `content` (optional, string)
-   `type` (required, enum: photo/video/text/announcement/achievement/event)
-   `media` (optional, array of URLs)
-   `thumbnail` (optional, string URL)
-   `visibility` (required, enum: public/students/staff/parents/guardians/teachers/admin_only/class_specific)
-   `visible_to_classes` (optional, array of class IDs, required if visibility is `class_specific`)
-   `is_pinned` (optional, boolean, default: false)
-   `expires_at` (optional, datetime, must be future date)
-   `allow_comments` (optional, boolean, default: true)
-   `allow_reactions` (optional, boolean, default: true)
-   `tags` (optional, array)
-   `category` (optional, string, max 100)

**Note:** No `school_id` or `user_id` needed! Auto-derived from context.

**Response (201):**

```json
{
  "message": "Story created successfully",
  "story": {
    "id": 2,
    "title": "Sports Day 2025!",
    "content": "Join us for our annual sports day...",
    "type": "event",
    "author": {
      "id": 5,
      "name": "Admin User"
    },
    ...
  }
}
```

---

### 4. **Update Story**

**Endpoint:** `PUT /api/v1/stories/{story_id}`

**Authorization:** Only story author or admin can update.

**Request Body:** (all fields optional)

```json
{
    "title": "Updated Title",
    "content": "Updated content...",
    "is_pinned": true,
    "expires_at": "2025-12-01T23:59:59"
}
```

**Response (200):**

```json
{
  "message": "Story updated successfully",
  "story": { ... }
}
```

---

### 5. **Delete Story**

**Endpoint:** `DELETE /api/v1/stories/{story_id}`

**Authorization:** Only story author or admin can delete.

**Response (200):**

```json
{
    "message": "Story deleted successfully"
}
```

---

### 6. **React to Story**

**Endpoint:** `POST /api/v1/stories/{story_id}/react`

**Request Body:**

```json
{
    "reaction_type": "celebrate"
}
```

**Reaction Types:**

-   `like` - 👍 Like
-   `love` - ❤️ Love
-   `celebrate` - 🎉 Celebrate
-   `support` - 🤝 Support
-   `insightful` - 💡 Insightful
-   `curious` - 🤔 Curious

**Response (200):**

```json
{
    "message": "Reaction added successfully",
    "reactions_count": 43
}
```

**Note:** If user already reacted, it updates the existing reaction.

---

### 7. **Remove Reaction**

**Endpoint:** `DELETE /api/v1/stories/{story_id}/unreact`

**Response (200):**

```json
{
    "message": "Reaction removed successfully",
    "reactions_count": 42
}
```

---

### 8. **Add Comment**

**Endpoint:** `POST /api/v1/stories/{story_id}/comments`

**Request Body:**

```json
{
    "comment": "This is amazing! Well done to all students.",
    "parent_id": null
}
```

**Fields:**

-   `comment` (required, string, max 1000)
-   `parent_id` (optional, integer) - ID of parent comment for replies

**Response (201):**

```json
{
    "message": "Comment added successfully",
    "comment": {
        "id": 10,
        "comment": "This is amazing! Well done to all students.",
        "user": {
            "id": 15,
            "name": "Parent Mike"
        },
        "created_at": "2025-11-23T15:30:00.000000Z"
    },
    "comments_count": 9
}
```

---

### 9. **Delete Comment**

**Endpoint:** `DELETE /api/v1/stories/{story_id}/comments/{comment_id}`

**Authorization:** Only comment author or admin can delete.

**Response (200):**

```json
{
    "message": "Comment deleted successfully",
    "comments_count": 8
}
```

---

### 10. **Share Story**

**Endpoint:** `POST /api/v1/stories/{story_id}/share`

**Note:** Increments share count (for tracking).

**Response (200):**

```json
{
    "message": "Story shared successfully",
    "shares_count": 15
}
```

---

### 11. **Story Analytics** (Admin Only)

**Endpoint:** `GET /api/v1/stories/{story_id}/analytics`

**Response (200):**

```json
{
    "analytics": {
        "views_count": 245,
        "reactions_count": 56,
        "comments_count": 12,
        "shares_count": 8,
        "reactions_breakdown": [
            {
                "reaction_type": "like",
                "count": 30
            },
            {
                "reaction_type": "love",
                "count": 15
            },
            {
                "reaction_type": "celebrate",
                "count": 11
            }
        ],
        "top_viewers": [
            {
                "id": 1,
                "user": {
                    "id": 10,
                    "name": "Jane Smith",
                    "email": "jane.smith@example.com"
                },
                "viewed_at": "2025-11-23T14:30:00.000000Z"
            }
        ]
    }
}
```

---

## Request/Response Examples

### Example 1: Create Photo Story

```bash
curl -X POST "https://api.compasse.net/api/v1/stories" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Science Fair Winners",
    "content": "Congratulations to all participants!",
    "type": "achievement",
    "media": ["https://cdn.example.com/science-fair.jpg"],
    "visibility": "public",
    "category": "academics",
    "tags": ["science", "achievement", "2025"]
  }'
```

### Example 2: Create Announcement (Expires in 24 hours)

```bash
curl -X POST "https://api.compasse.net/api/v1/stories" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "School Closure Notice",
    "content": "School will be closed tomorrow due to weather.",
    "type": "announcement",
    "visibility": "public",
    "is_pinned": true,
    "expires_at": "2025-11-24T23:59:59",
    "category": "announcement"
  }'
```

### Example 3: Create Class-Specific Story

```bash
curl -X POST "https://api.compasse.net/api/v1/stories" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Grade 5 Field Trip",
    "content": "Details about next week field trip...",
    "type": "event",
    "visibility": "class_specific",
    "visible_to_classes": [5, 6],
    "category": "events"
  }'
```

### Example 4: Get Recent Stories (Last 24 Hours)

```bash
curl -X GET "https://api.compasse.net/api/v1/stories?recent=true&hours=24" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

### Example 5: Get Pinned Announcements

```bash
curl -X GET "https://api.compasse.net/api/v1/stories?type=announcement&pinned=true" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Subdomain: westwood"
```

---

## Reactions & Comments

### Reaction Workflow

1. **User reacts** → `POST /stories/{id}/react`
2. **User changes reaction** → `POST /stories/{id}/react` (with different reaction_type)
3. **User removes reaction** → `DELETE /stories/{id}/unreact`

### Comment Workflow

1. **User comments** → `POST /stories/{id}/comments` (with `parent_id: null`)
2. **User replies to comment** → `POST /stories/{id}/comments` (with `parent_id: {comment_id}`)
3. **User deletes comment** → `DELETE /stories/{id}/comments/{comment_id}`

**Note:** Comments support nested replies (one level deep).

---

## Analytics

Track engagement with detailed analytics:

-   **Views Count** - Total unique views
-   **Reactions Count** - Total reactions
-   **Comments Count** - Total comments
-   **Shares Count** - Total shares
-   **Reactions Breakdown** - Count per reaction type
-   **Top Viewers** - Most engaged users

Use analytics to:

-   Understand content performance
-   Identify popular story types
-   Optimize posting times
-   Measure audience engagement

---

## Best Practices

### 1. **Use Appropriate Story Types**

-   `announcement` - Critical/important information
-   `achievement` - Celebrate success
-   `event` - Promote upcoming events
-   `photo`/`video` - Visual content
-   `text` - Quick updates

### 2. **Set Expiration Times**

-   Daily updates: 24 hours
-   Weekly events: 7 days
-   Announcements: Based on relevance
-   Achievements: No expiration (or 30 days)

### 3. **Use Pinning Strategically**

-   Pin critical announcements
-   Unpin when no longer relevant
-   Don't pin too many stories (max 2-3)

### 4. **Target Your Audience**

-   Use `visibility` to reach the right people
-   Use `class_specific` for targeted messages
-   Use `public` for school-wide announcements

### 5. **Encourage Engagement**

-   Enable comments and reactions
-   Reply to comments
-   Use engaging media (photos/videos)
-   Ask questions in story content

### 6. **Track Performance**

-   Check analytics regularly
-   Identify best-performing content
-   Adjust strategy based on data

### 7. **Moderate Content**

-   Admin can delete any story/comment
-   Monitor for inappropriate content
-   Respond to parent/guardian feedback

---

## Error Responses

### 400 Bad Request

```json
{
    "error": "School not found",
    "message": "Unable to determine school from tenant context"
}
```

### 403 Forbidden

```json
{
    "error": "Unauthorized",
    "message": "You can only update your own stories"
}
```

### 404 Not Found

```json
{
    "error": "You have not reacted to this story"
}
```

### 422 Validation Error

```json
{
    "error": "Validation failed",
    "messages": {
        "type": ["The type field is required."],
        "visibility": ["The visibility field is required."]
    }
}
```

### 500 Server Error

```json
{
    "error": "Failed to create story",
    "message": "SQLSTATE[...]"
}
```

---

## Integration Examples

### Frontend (React/Vue)

```javascript
// Fetch stories
const getStories = async () => {
    const response = await fetch("/api/v1/stories?recent=true", {
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": "westwood",
        },
    });
    return await response.json();
};

// Create story
const createStory = async (data) => {
    const response = await fetch("/api/v1/stories", {
        method: "POST",
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": "westwood",
            "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
    });
    return await response.json();
};

// React to story
const reactToStory = async (storyId, reactionType) => {
    const response = await fetch(`/api/v1/stories/${storyId}/react`, {
        method: "POST",
        headers: {
            Authorization: `Bearer ${token}`,
            "X-Subdomain": "westwood",
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ reaction_type: reactionType }),
    });
    return await response.json();
};
```

---

## Permissions

| Role            | Create | Update Own | Update Any | Delete Own | Delete Any | View | React | Comment |
| --------------- | ------ | ---------- | ---------- | ---------- | ---------- | ---- | ----- | ------- |
| Super Admin     | ✅     | ✅         | ✅         | ✅         | ✅         | ✅   | ✅    | ✅      |
| School Admin    | ✅     | ✅         | ✅         | ✅         | ✅         | ✅   | ✅    | ✅      |
| Teacher         | ✅     | ✅         | ❌         | ✅         | ❌         | ✅   | ✅    | ✅      |
| Staff           | ✅     | ✅         | ❌         | ✅         | ❌         | ✅   | ✅    | ✅      |
| Student         | ❌\*   | ❌         | ❌         | ❌         | ❌         | ✅   | ✅    | ✅      |
| Parent/Guardian | ❌     | ❌         | ❌         | ❌         | ❌         | ✅   | ✅    | ✅      |

\*Can be enabled per school policy

---

## Related Documentation

-   **Complete Admin APIs:** `COMPLETE_ADMIN_API_DOCUMENTATION.md`
-   **API Simplification:** `API_SIMPLIFICATION_SUMMARY.md`
-   **Frontend Integration:** `FRONTEND_INTEGRATION_GUIDE.md`

---

**Last Updated:** November 24, 2025  
**API Version:** 1.0  
**Feature:** School Stories  
**Status:** ✅ Ready for Production
