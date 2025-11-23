# Frontend Integration Guide
**Quick Reference for Updating Admin Pages**

## Overview

All admin APIs are **implemented, tested, and working 100%** on production. This guide shows you exactly how to integrate them into your frontend pages.

---

## Common Pattern for All Pages

Every admin page follows this pattern:

### 1. Import API Hooks
```typescript
import { 
  useTeachers, 
  useCreateTeacher, 
  useUpdateTeacher, 
  useDeleteTeacher 
} from '@/lib/api/teachers';
```

### 2. Use in Component
```typescript
function TeachersPage() {
  const { data: teachers, isLoading } = useTeachers();
  const createTeacher = useCreateTeacher();
  const updateTeacher = useUpdateTeacher();
  const deleteTeacher = useDeleteTeacher();

  if (isLoading) return <LoadingSpinner />;

  return (
    // Your UI here
  );
}
```

### 3. Handle CRUD Operations
```typescript
// Create
const handleCreate = (data) => {
  createTeacher.mutate(data, {
    onSuccess: () => {
      toast.success('Created successfully');
      // Refresh data or navigate
    },
    onError: (error) => {
      toast.error(error.message);
    }
  });
};

// Update
const handleUpdate = (id, data) => {
  updateTeacher.mutate({ id, data }, {
    onSuccess: () => toast.success('Updated successfully'),
    onError: (error) => toast.error(error.message)
  });
};

// Delete
const handleDelete = (id) => {
  deleteTeacher.mutate(id, {
    onSuccess: () => toast.success('Deleted successfully'),
    onError: (error) => toast.error(error.message)
  });
};
```

---

## Page-by-Page Integration

### 1. Teachers Page

**File:** `app/admin/teachers/page.tsx`

```typescript
import { useTeachers, useCreateTeacher, useUpdateTeacher, useDeleteTeacher } from '@/lib/api/teachers';
import { useSubjects } from '@/lib/api/academic';
import { useClasses } from '@/lib/api/academic';

export default function TeachersPage() {
  const { data: teachers, isLoading } = useTeachers();
  const { data: subjects } = useSubjects();
  const { data: classes } = useClasses();
  
  const createTeacher = useCreateTeacher();
  const updateTeacher = useUpdateTeacher();
  const deleteTeacher = useDeleteTeacher();

  // Form fields
  const formFields = {
    name: '',
    email: '',
    phone: '',
    qualification: '',
    experience_years: 0,
    subject_ids: [], // Multi-select from subjects
    class_ids: [],   // Multi-select from classes
    status: 'active'
  };

  // Your UI implementation
}
```

### 2. Staff Page

**File:** `app/admin/staff/page.tsx`

```typescript
import { useStaff, useCreateStaff, useUpdateStaff, useDeleteStaff } from '@/lib/api/staff';

export default function StaffPage() {
  const { data: staff, isLoading } = useStaff();
  const createStaff = useCreateStaff();
  
  const formFields = {
    name: '',
    email: '',
    phone: '',
    role: '',       // e.g., "Accountant", "Librarian"
    department: '', // e.g., "Finance", "Library"
    position: '',   // e.g., "Senior Accountant"
    status: 'active'
  };
}
```

### 3. Classes Page

**File:** `app/admin/classes/page.tsx`

```typescript
import { useClasses, useCreateClass, useUpdateClass, useDeleteClass } from '@/lib/api/academic';
import { useTeachers } from '@/lib/api/teachers';

export default function ClassesPage() {
  const { data: classes, isLoading } = useClasses();
  const { data: teachers } = useTeachers();
  const createClass = useCreateClass();
  
  const formFields = {
    name: '',            // e.g., "Grade 10"
    level: '',           // e.g., "Secondary"
    arms: ['A', 'B'],    // Array of strings
    capacity: 30,
    class_teacher_id: 0  // Dropdown from teachers
  };
}
```

### 4. Subjects Page

**File:** `app/admin/subjects/page.tsx`

```typescript
import { useSubjects, useCreateSubject, useUpdateSubject, useDeleteSubject } from '@/lib/api/academic';
import { useTeachers } from '@/lib/api/teachers';

export default function SubjectsPage() {
  const { data: subjects, isLoading } = useSubjects();
  const { data: teachers } = useTeachers();
  const createSubject = useCreateSubject();
  
  const formFields = {
    name: '',           // e.g., "Mathematics"
    code: '',           // e.g., "MATH101"
    description: '',
    teacher_ids: []     // Multi-select from teachers
  };
}
```

### 5. Timetable Page

**File:** `app/admin/timetable/page.tsx`

```typescript
import { 
  useTimetable, 
  useClassTimetable, 
  useTeacherTimetable,
  useCreateTimetable, 
  useUpdateTimetable, 
  useDeleteTimetable 
} from '@/lib/api/timetable';
import { useClasses, useSubjects } from '@/lib/api/academic';
import { useTeachers } from '@/lib/api/teachers';

export default function TimetablePage() {
  const [selectedClass, setSelectedClass] = useState(null);
  const [selectedTeacher, setSelectedTeacher] = useState(null);
  
  // Fetch timetable based on filters
  const { data: timetable } = useTimetable();
  const { data: classTimetable } = useClassTimetable(selectedClass);
  const { data: teacherTimetable } = useTeacherTimetable(selectedTeacher);
  
  const { data: classes } = useClasses();
  const { data: subjects } = useSubjects();
  const { data: teachers } = useTeachers();
  
  const createTimetable = useCreateTimetable();
  
  const formFields = {
    class_id: 0,       // Dropdown from classes
    subject_id: 0,     // Dropdown from subjects
    teacher_id: 0,     // Dropdown from teachers
    day: '',           // Dropdown: Monday-Friday
    start_time: '',    // Time input e.g., "08:00"
    end_time: '',      // Time input e.g., "09:00"
    room: ''           // Text input e.g., "Room 101"
  };
}
```

### 6. Announcements Page

**File:** `app/admin/announcements/page.tsx`

```typescript
import { 
  useAnnouncements, 
  useCreateAnnouncement, 
  useUpdateAnnouncement, 
  useDeleteAnnouncement,
  usePublishAnnouncement 
} from '@/lib/api/announcements';

export default function AnnouncementsPage() {
  const { data: announcements, isLoading } = useAnnouncements();
  const createAnnouncement = useCreateAnnouncement();
  const publishAnnouncement = usePublishAnnouncement();
  
  const formFields = {
    title: '',
    content: '',       // Textarea
    type: 'general',   // Dropdown or text
    status: 'draft',   // Dropdown: draft/published
    priority: 'normal' // Optional: high/normal/low
  };
  
  const handlePublish = (id) => {
    publishAnnouncement.mutate(id);
  };
}
```

### 7. Transport Page

**File:** `app/admin/transport/page.tsx`

```typescript
import { 
  useVehicles, 
  useCreateVehicle, 
  useUpdateVehicle, 
  useDeleteVehicle,
  useTransportRoutes, 
  useCreateTransportRoute,
  useDrivers, 
  useCreateDriver 
} from '@/lib/api/transport';

export default function TransportPage() {
  const { data: vehicles } = useVehicles();
  const { data: routes } = useTransportRoutes();
  const { data: drivers } = useDrivers();
  
  const createVehicle = useCreateVehicle();
  const createRoute = useCreateTransportRoute();
  const createDriver = useCreateDriver();
  
  // Vehicle Form Fields
  const vehicleFields = {
    vehicle_number: '',  // e.g., "BUS001"
    route_id: 0,         // Dropdown from routes
    driver_id: 0,        // Dropdown from drivers
    capacity: 50,
    vehicle_type: 'Bus', // Text or dropdown
    status: 'active'
  };
  
  // Route Form Fields
  const routeFields = {
    name: '',
    description: '',
    stops: ['Stop 1', 'Stop 2'] // Array of strings
  };
  
  // Driver Form Fields
  const driverFields = {
    name: '',
    phone: '',
    license_number: '',
    status: 'active'
  };
}
```

### 8. Reports Page

**File:** `app/admin/reports/page.tsx`

```typescript
import { 
  useAttendanceReport, 
  useAcademicReport, 
  useFinancialReport 
} from '@/lib/api/reports';
import { useClasses, useAcademicYears, useTerms } from '@/lib/api/academic';

export default function ReportsPage() {
  const [reportType, setReportType] = useState('attendance');
  const [filters, setFilters] = useState({
    start_date: '2025-01-01',
    end_date: '2025-12-31',
    class_id: null,
    term_id: null
  });
  
  // Fetch report based on type and filters
  const { data: attendanceReport } = useAttendanceReport(filters);
  const { data: academicReport } = useAcademicReport(filters);
  const { data: financialReport } = useFinancialReport(filters);
  
  const { data: classes } = useClasses();
  const { data: terms } = useTerms();
  const { data: academicYears } = useAcademicYears();
  
  // Filter UI
  const filterFields = {
    report_type: '',      // Dropdown: attendance/academic/financial
    start_date: '',       // Date input
    end_date: '',         // Date input
    class_id: null,       // Optional: Dropdown from classes
    term_id: null,        // Optional: Dropdown from terms
    academic_year: null   // Optional: Dropdown from academic years
  };
}
```

### 9. Houses Page

**File:** `app/admin/houses/page.tsx`

```typescript
import { 
  useHouses, 
  useCreateHouse, 
  useUpdateHouse, 
  useDeleteHouse,
  useHouseMembers,
  useHousePoints,
  useAddHousePoints 
} from '@/lib/api/houses';
import { useStudents } from '@/lib/api/students';

export default function HousesPage() {
  const { data: houses, isLoading } = useHouses();
  const { data: students } = useStudents();
  const createHouse = useCreateHouse();
  const addHousePoints = useAddHousePoints();
  
  const formFields = {
    name: '',           // e.g., "Red House"
    color: '#FF0000',   // Color picker
    description: '',
    points: 0           // Initial points (usually 0)
  };
  
  const handleAddPoints = (houseId, points, reason) => {
    addHousePoints.mutate({ houseId, points, reason });
  };
}
```

### 10. Sports Page

**File:** `app/admin/sports/page.tsx`

```typescript
import { 
  useSportsActivities, 
  useCreateSportsActivity,
  useSportsTeams, 
  useCreateSportsTeam,
  useSportsEvents, 
  useCreateSportsEvent 
} from '@/lib/api/sports';
import { useStudents } from '@/lib/api/students';
import { useTeachers } from '@/lib/api/teachers';

export default function SportsPage() {
  const { data: activities } = useSportsActivities();
  const { data: teams } = useSportsTeams();
  const { data: events } = useSportsEvents();
  const { data: students } = useStudents();
  const { data: teachers } = useTeachers();
  
  // Activity Form Fields
  const activityFields = {
    name: '',           // e.g., "Football"
    description: '',
    category: '',       // e.g., "Team Sport"
    coach_id: 0,        // Dropdown from teachers
    schedule: ''        // e.g., "Monday, Wednesday, Friday"
  };
  
  // Team Form Fields
  const teamFields = {
    name: '',           // e.g., "Junior Football Team"
    sport: '',          // e.g., "Football"
    coach_id: 0,        // Dropdown from teachers
    member_ids: []      // Multi-select from students
  };
  
  // Event Form Fields
  const eventFields = {
    name: '',           // e.g., "Inter-House Match"
    description: '',
    sport: '',
    date: '',           // Date input
    venue: '',          // e.g., "School Field"
    team_ids: []        // Multi-select from teams
  };
}
```

### 11. Inventory Page

**File:** `app/admin/inventory/page.tsx`

```typescript
import { 
  useInventoryItems, 
  useCreateInventoryItem,
  useUpdateInventoryItem,
  useDeleteInventoryItem,
  useInventoryCategories, 
  useCreateInventoryCategory,
  useCheckoutItem,
  useReturnItem 
} from '@/lib/api/inventory';

export default function InventoryPage() {
  const { data: items } = useInventoryItems();
  const { data: categories } = useInventoryCategories();
  
  const createItem = useCreateInventoryItem();
  const createCategory = useCreateInventoryCategory();
  const checkoutItem = useCheckoutItem();
  const returnItem = useReturnItem();
  
  // Item Form Fields
  const itemFields = {
    name: '',               // e.g., "Laptop"
    description: '',
    category_id: 0,         // Dropdown from categories
    quantity: 0,
    unit: '',               // e.g., "pieces"
    min_stock_level: 0,     // Alert threshold
    location: ''            // e.g., "Store Room 1"
  };
  
  // Category Form Fields
  const categoryFields = {
    name: '',               // e.g., "Electronics"
    description: ''
  };
  
  // Checkout Form
  const checkoutFields = {
    item_id: 0,
    quantity: 0,
    checkout_to: '',        // Person name
    purpose: '',
    expected_return_date: ''
  };
}
```

---

## API Hook Patterns

All API hooks follow these patterns:

### Query Hooks (GET)
```typescript
// List
const { data, isLoading, error, refetch } = useTeachers();

// Single Item
const { data, isLoading } = useTeacher(id);

// With Params
const { data } = useAttendanceReport({ start_date, end_date });
```

### Mutation Hooks (POST/PUT/DELETE)
```typescript
// Create
const createMutation = useCreateTeacher();
createMutation.mutate(data, {
  onSuccess: () => {},
  onError: (error) => {}
});

// Update
const updateMutation = useUpdateTeacher();
updateMutation.mutate({ id, data }, {
  onSuccess: () => {},
  onError: (error) => {}
});

// Delete
const deleteMutation = useDeleteTeacher();
deleteMutation.mutate(id, {
  onSuccess: () => {},
  onError: (error) => {}
});
```

---

## Common Components

### Loading State
```typescript
if (isLoading) {
  return (
    <div className="flex justify-center items-center h-64">
      <Spinner />
    </div>
  );
}
```

### Error State
```typescript
if (error) {
  return (
    <Alert variant="destructive">
      <AlertTitle>Error</AlertTitle>
      <AlertDescription>{error.message}</AlertDescription>
    </Alert>
  );
}
```

### Success Toast
```typescript
import { toast } from 'sonner';

createMutation.mutate(data, {
  onSuccess: () => {
    toast.success('Created successfully');
    form.reset();
  }
});
```

### Error Toast
```typescript
onError: (error) => {
  toast.error(error.message || 'An error occurred');
}
```

---

## Form Validation

Use Zod schemas for form validation:

```typescript
import { z } from 'zod';

const teacherSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  phone: z.string().optional(),
  qualification: z.string(),
  experience_years: z.number().min(0),
  subject_ids: z.array(z.number()),
  class_ids: z.array(z.number()),
  status: z.enum(['active', 'inactive'])
});

type TeacherFormData = z.infer<typeof teacherSchema>;
```

---

## Multi-Select Dropdowns

For relationships (e.g., teachers -> subjects):

```typescript
import { MultiSelect } from '@/components/ui/multi-select';

<MultiSelect
  options={subjects.map(s => ({ label: s.name, value: s.id }))}
  selected={selectedSubjects}
  onChange={setSelectedSubjects}
  placeholder="Select subjects"
/>
```

---

## Data Table

Use shadcn/ui DataTable component:

```typescript
import { DataTable } from '@/components/ui/data-table';

const columns = [
  {
    accessorKey: "name",
    header: "Name",
  },
  {
    accessorKey: "email",
    header: "Email",
  },
  {
    id: "actions",
    cell: ({ row }) => (
      <DropdownMenu>
        <DropdownMenuItem onClick={() => handleEdit(row.original)}>
          Edit
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => handleDelete(row.original.id)}>
          Delete
        </DropdownMenuItem>
      </DropdownMenu>
    ),
  },
];

<DataTable columns={columns} data={teachers.data} />
```

---

## Pagination

Handle pagination from API:

```typescript
const { data: teachers } = useTeachers({ page: currentPage, per_page: 15 });

<Pagination
  currentPage={teachers.current_page}
  totalPages={Math.ceil(teachers.total / teachers.per_page)}
  onPageChange={setCurrentPage}
/>
```

---

## Search and Filters

Add search and filters:

```typescript
const [search, setSearch] = useState('');
const [filters, setFilters] = useState({ status: 'all', class_id: null });

const { data } = useTeachers({ 
  search, 
  ...filters 
});

<Input
  placeholder="Search..."
  value={search}
  onChange={(e) => setSearch(e.target.value)}
/>

<Select value={filters.status} onValueChange={(value) => setFilters({ ...filters, status: value })}>
  <SelectItem value="all">All</SelectItem>
  <SelectItem value="active">Active</SelectItem>
  <SelectItem value="inactive">Inactive</SelectItem>
</Select>
```

---

## Testing Checklist

For each page, verify:

- [ ] List/GET endpoint works
- [ ] Create/POST endpoint works
- [ ] Update/PUT endpoint works
- [ ] Delete/DELETE endpoint works
- [ ] Loading states show correctly
- [ ] Error states show correctly
- [ ] Success toasts appear
- [ ] Form validation works
- [ ] Multi-select dropdowns work (for relationships)
- [ ] Pagination works
- [ ] Search works
- [ ] Filters work

---

## Quick Start Steps

1. **Pick a page** from the list above
2. **Copy the code template** for that page
3. **Import the API hooks** from `lib/api/*`
4. **Replace dummy data** with API hook responses
5. **Add form handling** for create/update operations
6. **Add error/loading states**
7. **Test CRUD operations**
8. **Move to next page**

---

## Need Help?

- Check `COMPLETE_ADMIN_API_DOCUMENTATION.md` for API details
- All APIs are tested and working on `api.compasse.net`
- Use the code patterns above as templates
- Test on production with `X-Subdomain` header

---

**Status: Ready for Frontend Integration** ✅

