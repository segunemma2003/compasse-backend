# School Customization — Landing Page & Branding

> **Base URL:** `https://{subdomain}.compasse.africa/api/v1/`
> **Public endpoints:** No auth required
> **Protected endpoints:** `Authorization: Bearer {token}` + tenant context required
> **Module gate:** None — available to all authenticated school admins

---

## Overview

Every school on Compasse gets a public-facing landing page. Admins can:

1. **Choose a template** from 5 predefined designs
2. **Configure branding** — colours, hero image/video, CTA button
3. **Add school information** — about text, vision, mission, contact details
4. **Set up social links** — Facebook, Twitter, Instagram, YouTube, LinkedIn
5. **Upload assets** — hero image, about image, Open Graph image
6. **Configure SEO** — page title, meta description, keywords
7. **Enable/disable features** — choose which sections appear on the landing page

All landing page settings are stored in the `settings` table under `category = 'landing_page'`. No separate migration is required.

---

## User Stories

> **As a school admin**, I want to choose a template for our public landing page so the school's website looks professional without needing a web developer.

> **As a school admin**, I want to upload a hero banner image and set a tagline so that parents see the right first impression when they visit our subdomain.

> **As a parent**, I want to visit `greenfield.compasse.africa` and immediately see the school's name, logo, contact details, and a "Apply Now" button.

> **As a frontend developer**, I want a single public API endpoint that returns all landing page data so I can render the school website with one request.

---

## Templates

Five predefined templates are available. Templates define the layout structure, default colour palette, and which feature sections are supported.

| ID | Name | Description |
|----|------|-------------|
| `classic` | Classic | Traditional navy + white, bulletin-board sidebar, news ticker |
| `modern` | Modern | Full-width hero, card layout, dark sticky header, stats counter |
| `minimal` | Minimal | Clean white, icon-driven feature blocks, thin typographic hierarchy |
| `vibrant` | Vibrant | Gradient header, animated counters, social media feed strip |
| `academic` | Academic | Ivory background, serif headings, crest-first branding — for universities |

Each template ships with a default colour palette that is overridable per school.

---

## API Endpoints

### Public Endpoints (no auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/schools/landing-page/templates` | List all available templates with previews |
| GET | `/api/v1/public/{subdomain}` | Get a school's full landing page data (for rendering) |

### Authenticated Endpoints (tenant + auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/schools/landing-page` | Get the current school's landing page config |
| PUT | `/api/v1/schools/landing-page` | Update landing page settings (partial update supported) |
| POST | `/api/v1/schools/landing-page/upload-asset` | Upload a hero/about/OG image |

---

## Request & Response Examples

### GET /api/v1/schools/landing-page/templates

No auth required.

**Request:**
```http
GET /api/v1/schools/landing-page/templates
Accept: application/json
```

**Response `200`:**
```json
{
  "templates": [
    {
      "id": "classic",
      "name": "Classic",
      "description": "Traditional school website look — white background, navy header, bulletin-board sidebar.",
      "preview_url": "/templates/previews/classic.png",
      "colors": {
        "primary": "#1a3a6b",
        "secondary": "#f5a623",
        "background": "#ffffff",
        "text": "#333333"
      },
      "features": ["hero_image", "news_ticker", "quick_links", "contact_form"]
    },
    {
      "id": "modern",
      "name": "Modern",
      "description": "Full-width hero, card-based layout, bold typography, and a dark sticky header.",
      "preview_url": "/templates/previews/modern.png",
      "colors": {
        "primary": "#0f172a",
        "secondary": "#6366f1",
        "background": "#f8fafc",
        "text": "#1e293b"
      },
      "features": ["hero_video", "stats_counter", "testimonials", "cta_banner"]
    },
    {
      "id": "minimal",
      "name": "Minimal",
      "description": "Clean white canvas — icon-driven feature blocks, thin typographic hierarchy.",
      "preview_url": "/templates/previews/minimal.png",
      "colors": {
        "primary": "#18181b",
        "secondary": "#22c55e",
        "background": "#ffffff",
        "text": "#3f3f46"
      },
      "features": ["hero_image", "feature_icons", "contact_form"]
    },
    {
      "id": "vibrant",
      "name": "Vibrant",
      "description": "Bright gradient header, animated counters, social media feed strip.",
      "preview_url": "/templates/previews/vibrant.png",
      "colors": {
        "primary": "#7c3aed",
        "secondary": "#f59e0b",
        "background": "#faf5ff",
        "text": "#1f1f1f"
      },
      "features": ["hero_image", "stats_counter", "news_ticker", "social_strip", "cta_banner"]
    },
    {
      "id": "academic",
      "name": "Academic",
      "description": "Formal ivory background, serif headings, crest-first branding.",
      "preview_url": "/templates/previews/academic.png",
      "colors": {
        "primary": "#1d4ed8",
        "secondary": "#b45309",
        "background": "#fffbeb",
        "text": "#292524"
      },
      "features": ["hero_image", "quick_links", "news_section", "contact_form", "departments_grid"]
    }
  ],
  "count": 5
}
```

---

### GET /api/v1/public/{subdomain}

No auth required. Used by the frontend to render the school's landing page.

**Request:**
```http
GET /api/v1/public/greenfield
Accept: application/json
```

**Response `200`:**
```json
{
  "school": {
    "name": "Greenfield Academy",
    "logo": "https://cdn.compasse.africa/schools/1/logo.png",
    "email": "info@greenfieldacademy.edu.ng",
    "phone": "+234 801 234 5678",
    "address": "14 Education Road, Lagos, Nigeria",
    "website": "https://greenfieldacademy.edu.ng"
  },
  "template": {
    "id": "modern",
    "name": "Modern",
    "colors": {
      "primary": "#0f172a",
      "secondary": "#6366f1",
      "background": "#f8fafc",
      "text": "#1e293b"
    },
    "features": ["hero_video", "stats_counter", "testimonials", "cta_banner"]
  },
  "branding": {
    "primary_color": "#1a3a6b",
    "secondary_color": "#d4a017",
    "background_color": "#ffffff",
    "text_color": "#333333",
    "custom_css": null
  },
  "hero": {
    "headline": "Shaping Tomorrow's Leaders Today",
    "subheadline": "A Premier Secondary School in Lagos — Nurturing Excellence Since 1998",
    "image_url": "https://cdn.compasse.africa/schools/1/landing/hero_image/banner.jpg",
    "video_url": null,
    "cta_text": "Apply for Admission",
    "cta_url": "https://greenfield.compasse.africa/apply"
  },
  "about": {
    "tagline": "Where Excellence Meets Character",
    "text": "Greenfield Academy is committed to providing world-class education in a nurturing environment...",
    "image_url": "https://cdn.compasse.africa/schools/1/landing/about_image/building.jpg",
    "vision": "To produce well-rounded, globally competitive graduates.",
    "mission": "To deliver quality education through innovative teaching and strong values."
  },
  "contact": {
    "address": "14 Education Road, Lekki Phase 1, Lagos",
    "phone": "+234 801 234 5678",
    "email": "info@greenfieldacademy.edu.ng",
    "map_embed_url": "https://maps.google.com/maps?q=Greenfield+Academy+Lagos&output=embed",
    "working_hours": "Mon–Fri: 7:30am – 3:30pm"
  },
  "social": {
    "facebook": "https://facebook.com/GreenfieldAcademyLagos",
    "twitter": "https://twitter.com/GreenfieldLagos",
    "instagram": "https://instagram.com/greenfield_academy",
    "youtube": "https://youtube.com/@GreenfieldAcademy",
    "linkedin": null
  },
  "features": ["hero_image", "stats_counter", "news_ticker", "contact_form"],
  "seo": {
    "title": "Greenfield Academy — Premier Secondary School in Lagos",
    "description": "Greenfield Academy offers world-class JSS and SSS education in Lagos, Nigeria. Apply for admission today.",
    "keywords": "secondary school lagos, best school lagos, greenfield academy",
    "og_image": "https://cdn.compasse.africa/schools/1/logo.png"
  }
}
```

**Response `404`:**
```json
{ "error": "School not found" }
```

---

### GET /api/v1/schools/landing-page

Returns the current school's config plus all available templates (for the admin UI picker).

**Request:**
```http
GET /api/v1/schools/landing-page
Authorization: Bearer 1|abc123...
X-Subdomain: greenfield
Accept: application/json
```

**Response `200`:**
```json
{
  "school": {
    "id": 1,
    "name": "Greenfield Academy",
    "logo": "https://cdn.compasse.africa/schools/1/logo.png",
    "email": "info@greenfieldacademy.edu.ng"
  },
  "template": {
    "id": "modern",
    "name": "Modern",
    "colors": { "primary": "#0f172a", "secondary": "#6366f1" }
  },
  "settings": {
    "template": "modern",
    "primary_color": "#1a3a6b",
    "hero_headline": "Shaping Tomorrow's Leaders Today",
    "hero_cta_text": "Apply for Admission",
    "contact_phone": "+234 801 234 5678",
    "social_facebook": "https://facebook.com/GreenfieldAcademyLagos"
  },
  "all_templates": [ ... ]
}
```

---

### PUT /api/v1/schools/landing-page

Partial update — only send the keys you want to change.

**Request:**
```http
PUT /api/v1/schools/landing-page
Authorization: Bearer 1|abc123...
X-Subdomain: greenfield
Content-Type: application/json
```

```json
{
  "template": "modern",
  "primary_color": "#1a3a6b",
  "secondary_color": "#d4a017",
  "hero_headline": "Shaping Tomorrow's Leaders Today",
  "hero_subheadline": "A Premier Secondary School in Lagos Since 1998",
  "hero_cta_text": "Apply for Admission",
  "hero_cta_url": "https://greenfield.compasse.africa/apply",
  "about_tagline": "Where Excellence Meets Character",
  "about_text": "Greenfield Academy is committed to providing world-class education...",
  "vision": "To produce well-rounded, globally competitive graduates.",
  "mission": "To deliver quality education through innovative teaching and strong values.",
  "contact_phone": "+234 801 234 5678",
  "contact_email": "info@greenfieldacademy.edu.ng",
  "contact_address": "14 Education Road, Lekki Phase 1, Lagos",
  "working_hours": "Mon–Fri: 7:30am – 3:30pm",
  "map_embed_url": "https://maps.google.com/maps?q=Greenfield+Academy+Lagos&output=embed",
  "social_facebook": "https://facebook.com/GreenfieldAcademyLagos",
  "social_twitter": "https://twitter.com/GreenfieldLagos",
  "social_instagram": "https://instagram.com/greenfield_academy",
  "seo_title": "Greenfield Academy — Premier Secondary School in Lagos",
  "seo_description": "World-class JSS and SSS education in Lagos. Apply today.",
  "seo_keywords": "secondary school lagos, greenfield academy",
  "enabled_features": ["hero_image", "stats_counter", "contact_form", "news_ticker"]
}
```

**Response `200`:**
```json
{
  "message": "Landing page updated successfully",
  "settings": {
    "template": "modern",
    "primary_color": "#1a3a6b",
    "hero_headline": "Shaping Tomorrow's Leaders Today",
    ...
  }
}
```

**Validation error `422`:**
```json
{
  "errors": {
    "template": ["The selected template is invalid."],
    "contact_email": ["The contact email must be a valid email address."]
  }
}
```

---

### POST /api/v1/schools/landing-page/upload-asset

Upload an image for the landing page. Returns the public URL and automatically saves it to the corresponding setting key.

**Request (multipart/form-data):**
```http
POST /api/v1/schools/landing-page/upload-asset
Authorization: Bearer 1|abc123...
X-Subdomain: greenfield
Content-Type: multipart/form-data
```

Form fields:
| Field | Type | Values | Description |
|-------|------|--------|-------------|
| `file` | File | jpg, jpeg, png, webp, gif (max 5MB) | The image to upload |
| `type` | string | `hero_image`, `about_image`, `logo`, `og_image` | Asset category |

**Response `201`:**
```json
{
  "message": "Asset uploaded successfully",
  "url": "https://cdn.compasse.africa/schools/1/landing/hero_image/banner.jpg",
  "key": "hero_image_url"
}
```

---

## Configurable Settings Reference

All keys accepted by `PUT /api/v1/schools/landing-page`:

### Template & Colours

| Key | Type | Description |
|-----|------|-------------|
| `template` | string | Template ID: `classic`, `modern`, `minimal`, `vibrant`, `academic` |
| `primary_color` | string | Primary brand colour (hex, e.g. `#1a3a6b`) |
| `secondary_color` | string | Accent colour |
| `background_color` | string | Page background colour |
| `text_color` | string | Body text colour |
| `custom_css` | string | Raw CSS injected into `<style>` (max 10,000 chars) |

### Hero Section

| Key | Type | Description |
|-----|------|-------------|
| `hero_headline` | string | Main hero heading |
| `hero_subheadline` | string | Sub-heading below headline |
| `hero_image_url` | string | URL of the hero banner image (upload via upload-asset) |
| `hero_video_url` | string | YouTube/Vimeo embed URL for video hero |
| `hero_cta_text` | string | Call-to-action button label (e.g. "Apply Now") |
| `hero_cta_url` | string | CTA button destination URL |

### About Section

| Key | Type | Description |
|-----|------|-------------|
| `about_tagline` | string | Short tagline (e.g. "Where Excellence Meets Character") |
| `about_text` | string | Long-form about paragraph |
| `about_image_url` | string | About section image URL |
| `vision` | string | School vision statement |
| `mission` | string | School mission statement |

### Contact Section

| Key | Type | Description |
|-----|------|-------------|
| `contact_address` | string | Physical address shown on landing page |
| `contact_phone` | string | Contact phone number |
| `contact_email` | string | Contact email |
| `map_embed_url` | string | Google Maps embed URL |
| `working_hours` | string | Office hours text |

### Social Links

| Key | Type |
|-----|------|
| `social_facebook` | URL string |
| `social_twitter` | URL string |
| `social_instagram` | URL string |
| `social_youtube` | URL string |
| `social_linkedin` | URL string |

### SEO

| Key | Type | Description |
|-----|------|-------------|
| `seo_title` | string | `<title>` tag (max 255 chars) |
| `seo_description` | string | Meta description (max 500 chars) |
| `seo_keywords` | string | Meta keywords (comma-separated) |
| `seo_og_image` | string | Open Graph image URL (1200×630 recommended) |

### Feature Toggle

| Key | Type | Description |
|-----|------|-------------|
| `enabled_features` | array | List of feature section IDs to show on landing page |

Available feature IDs by template:

| Feature ID | Description | Available In |
|-----------|-------------|-------------|
| `hero_image` | Hero banner with image | classic, minimal, vibrant, academic |
| `hero_video` | Video background hero | modern |
| `stats_counter` | Animated counters (students, teachers, years) | modern, vibrant |
| `news_ticker` | Scrolling news/announcements strip | classic, vibrant |
| `quick_links` | Icon grid of quick navigation links | classic, academic |
| `contact_form` | Contact inquiry form | classic, minimal, academic |
| `testimonials` | Parent/alumni testimonials slider | modern |
| `cta_banner` | Full-width call-to-action banner | modern, vibrant |
| `social_strip` | Social media feed strip | vibrant |
| `feature_icons` | Icon + text feature highlights | minimal |
| `news_section` | Full news article listing | academic |
| `departments_grid` | Grid of school departments | academic |

---

## Business Rules

1. **Partial updates** — `PUT /schools/landing-page` merges with existing settings; keys not sent are unchanged
2. **Template defaults** — if `primary_color` etc. are not set, the selected template's default colours are used at display time (in the `GET /public/{subdomain}` response)
3. **Asset uploads** — uploaded files are stored at `schools/{id}/landing/{type}/` and served via `Storage::url()`
4. **`enabled_features` is JSON** — sent as an array in requests, stored as JSON string, returned as array
5. **No module gate** — landing page customisation is available to all tenant admins on any subscription plan

---

## Frontend Integration

### Admin UI — Landing Page Builder

```typescript
// 1. Load available templates (once, can be cached)
const { templates } = await fetch('/api/v1/schools/landing-page/templates').then(r => r.json());

// 2. Load current settings
const { settings, template } = await api('schools/landing-page').then(r => r.json());

// 3. Render template picker with live preview
<TemplatePicker
  templates={templates}
  selected={settings.template ?? 'classic'}
  onChange={(id) => setForm({ ...form, template: id })}
/>

// 4. Colour picker for brand colours
<ColourPicker label="Primary Colour" value={form.primary_color ?? template.colors.primary}
  onChange={(v) => setForm({ ...form, primary_color: v })} />

// 5. Upload hero image
const uploadHero = async (file: File) => {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('type', 'hero_image');

  const res = await fetch(`/api/v1/schools/landing-page/upload-asset`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: formData,
  });
  const { url } = await res.json();
  setForm({ ...form, hero_image_url: url });
};

// 6. Save all changes
const save = async () => {
  await api('schools/landing-page', {
    method: 'PUT',
    body: JSON.stringify(form),
  });
  toast.success('Landing page updated!');
};
```

### Rendering the Public Landing Page

```typescript
// pages/[subdomain].tsx (Next.js)
export async function getServerSideProps({ params }) {
  const data = await fetch(
    `https://compasse.africa/api/v1/public/${params.subdomain}`
  ).then(r => r.json());

  if (data.error) return { notFound: true };

  return { props: { landingPage: data } };
}

export default function SchoolLanding({ landingPage }) {
  const { school, template, hero, about, contact, social, seo, branding } = landingPage;

  return (
    <>
      <Head>
        <title>{seo.title}</title>
        <meta name="description" content={seo.description} />
        <meta property="og:image" content={seo.og_image} />
        {branding.custom_css && <style>{branding.custom_css}</style>}
      </Head>
      <TemplateRenderer template={template.id} data={landingPage} />
    </>
  );
}
```

### SEO & Open Graph

The `GET /public/{subdomain}` response contains all SEO fields needed to populate `<head>` tags. Use the `seo_og_image` (1200×630px recommended) for social sharing previews.

```html
<title>{seo.title}</title>
<meta name="description" content="{seo.description}" />
<meta name="keywords" content="{seo.keywords}" />
<meta property="og:title" content="{seo.title}" />
<meta property="og:description" content="{seo.description}" />
<meta property="og:image" content="{seo.og_image}" />
<meta property="og:url" content="https://{subdomain}.compasse.africa" />
<meta name="twitter:card" content="summary_large_image" />
```
