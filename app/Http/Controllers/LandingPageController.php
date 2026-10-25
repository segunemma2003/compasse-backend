<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Events\LandingPageUpdated;
use App\Models\School;

class LandingPageController extends Controller
{
    /**
     * Public landing page cache TTL (10 minutes).
     * Invalidated immediately on every save via a queued listener.
     */
    private const PUBLIC_CACHE_TTL = 600;

    /**
     * Templates are static platform data — cache for 1 hour.
     */
    private const TEMPLATES_CACHE_TTL = 3600;

    /**
     * Predefined landing page templates (platform-level, not per-tenant DB).
     */
    private const TEMPLATES = [
        [
            'id'          => 'classic',
            'name'        => 'Classic',
            'description' => 'Traditional school website look — white background, navy header, news ticker, quick-links sidebar.',
            'preview_url' => '/templates/previews/classic.png',
            'colors'      => ['primary' => '#1a3a6b', 'secondary' => '#f5a623', 'background' => '#ffffff', 'text' => '#333333'],
            'features'    => ['hero_image', 'news_ticker', 'quick_links', 'contact_form'],
        ],
        [
            'id'          => 'modern',
            'name'        => 'Modern',
            'description' => 'Full-width hero, card-based layout, bold typography, dark sticky header, stats counter.',
            'preview_url' => '/templates/previews/modern.png',
            'colors'      => ['primary' => '#0f172a', 'secondary' => '#6366f1', 'background' => '#f8fafc', 'text' => '#1e293b'],
            'features'    => ['hero_video', 'stats_counter', 'testimonials', 'cta_banner'],
        ],
        [
            'id'          => 'minimal',
            'name'        => 'Minimal',
            'description' => 'Clean white canvas — icon-driven feature blocks, generous whitespace, thin typographic hierarchy.',
            'preview_url' => '/templates/previews/minimal.png',
            'colors'      => ['primary' => '#18181b', 'secondary' => '#22c55e', 'background' => '#ffffff', 'text' => '#3f3f46'],
            'features'    => ['hero_image', 'feature_icons', 'contact_form'],
        ],
        [
            'id'          => 'vibrant',
            'name'        => 'Vibrant',
            'description' => 'Bright gradient header, animated counters, social media strip, full-width CTA banner.',
            'preview_url' => '/templates/previews/vibrant.png',
            'colors'      => ['primary' => '#7c3aed', 'secondary' => '#f59e0b', 'background' => '#faf5ff', 'text' => '#1f1f1f'],
            'features'    => ['hero_image', 'stats_counter', 'news_ticker', 'social_strip', 'cta_banner'],
        ],
        [
            'id'          => 'academic',
            'name'        => 'Academic',
            'description' => 'Formal ivory background, serif headings, crest-first branding, departments grid — suited for universities and colleges.',
            'preview_url' => '/templates/previews/academic.png',
            'colors'      => ['primary' => '#1d4ed8', 'secondary' => '#b45309', 'background' => '#fffbeb', 'text' => '#292524'],
            'features'    => ['hero_image', 'quick_links', 'news_section', 'contact_form', 'departments_grid'],
        ],
        [
            'id'          => 'summit',
            'name'        => 'Summit',
            'description' => 'Full-screen photo banner with a bold capital headline, see-through menu with drop-downs and a bright call-to-action. Confident and international.',
            'preview_url' => null,
            'colors'      => ['primary' => '#0b3b6f', 'secondary' => '#f5c518', 'background' => '#ffffff', 'text' => '#1f2937'],
            'features'    => ['stats_counter', 'programmes', 'welcome', 'news_section', 'events', 'gallery', 'testimonials', 'partners', 'cta_banner'],
        ],
        [
            'id'          => 'sunshine',
            'name'        => 'Sunshine',
            'description' => 'Warm cream and crimson with slab-serif headings: school-section cards, an admission banner, achievements and big counters. Friendly and lively.',
            'preview_url' => null,
            'colors'      => ['primary' => '#c8102e', 'secondary' => '#f2a900', 'background' => '#fffbea', 'text' => '#2d2a26'],
            'features'    => ['welcome', 'programmes', 'cta_banner', 'news_section', 'stats_counter', 'events', 'gallery', 'testimonials'],
        ],
        [
            'id'          => 'shepherd',
            'name'        => 'Shepherd',
            'description' => 'Condensed capital headings with a crimson underline: welcome, counters, upcoming events with time and venue, staff portraits and blog articles.',
            'preview_url' => null,
            'colors'      => ['primary' => '#d6203d', 'secondary' => '#1f2a44', 'background' => '#ffffff', 'text' => '#222222'],
            'features'    => ['welcome', 'stats_counter', 'events', 'staff', 'news_section', 'programmes', 'gallery', 'cta_banner'],
        ],
        [
            'id'          => 'prestige',
            'name'        => 'Prestige',
            'description' => 'Navy and sand with an elegant serif and large photography: generous white space for an established, premium feel.',
            'preview_url' => null,
            'colors'      => ['primary' => '#0c1e3c', 'secondary' => '#c19a6b', 'background' => '#fbf8f3', 'text' => '#1c2433'],
            'features'    => ['welcome', 'programmes', 'stats_counter', 'gallery', 'testimonials', 'news_section', 'partners'],
        ],
        [
            'id'          => 'evergreen',
            'name'        => 'Evergreen',
            'description' => 'Deep green with orange and maroon accents: centred introduction, school-section cards, a brochure band and "our approach" cards.',
            'preview_url' => null,
            'colors'      => ['primary' => '#03624c', 'secondary' => '#e05a00', 'background' => '#ffffff', 'text' => '#2b2b2b'],
            'features'    => ['programmes', 'cta_banner', 'values', 'welcome', 'news_section', 'gallery', 'testimonials', 'stats_counter'],
        ],
        [
            'id'          => 'crimson',
            'name'        => 'Crimson',
            'description' => 'Bold red with clean sans headings: years-of-excellence badge, partners and accreditation strip, campuses, news and gallery.',
            'preview_url' => null,
            'colors'      => ['primary' => '#c8000f', 'secondary' => '#111111', 'background' => '#ffffff', 'text' => '#262626'],
            'features'    => ['stats_counter', 'welcome', 'partners', 'programmes', 'news_section', 'events', 'gallery', 'testimonials'],
        ],
    ];

    /** Lists the editor manages (stored as JSON text, returned decoded on the public site). */
    public const CONTENT_LISTS = ['stats', 'programmes', 'news', 'events', 'staff', 'gallery', 'testimonials', 'partners', 'quick_links', 'admission_steps', 'values'];

    /** Free-text content used by the inner pages. */
    public const CONTENT_TEXTS = ['announcement', 'motto', 'founded_year', 'welcome_title', 'welcome_text', 'welcome_name', 'welcome_role', 'welcome_photo_url',
        'history_text', 'academics_text', 'admissions_text', 'brochure_url', 'admission_form_url', 'gallery_text'];

    /** Decoded lists + texts for the public site (only what the school entered). */
    public static function content(array $settings): array
    {
        $out = [];
        foreach (self::CONTENT_LISTS as $k) {
            $v = json_decode((string) ($settings[$k] ?? '[]'), true);
            $out[$k] = is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        }
        foreach (self::CONTENT_TEXTS as $k) {
            $out[$k] = ($settings[$k] ?? '') !== '' ? $settings[$k] : null;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Public endpoints (no auth)
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/schools/landing-page/templates
     *
     * Returns all available templates.
     * Cached for 1 hour — templates are static platform data.
     */
    public function getTemplates(): JsonResponse
    {
        $templates = Cache::remember('landing:templates:v2', self::TEMPLATES_CACHE_TTL, fn () => self::TEMPLATES);

        return response()->json([
            'templates' => $templates,
            'count'     => count($templates),
        ]);
    }

    /**
     * GET /api/v1/public/{subdomain}
     *
     * Returns full landing page data for a school by subdomain.
     * Response is cached in Redis for 10 minutes per subdomain.
     * Cache is invalidated on every admin save via a queued listener.
     *
     * Performance goal: < 50 ms on warm cache, < 3 s on cold start.
     */
    public function publicLandingPage(string $subdomain): JsonResponse
    {
        $subdomain = strtolower(trim($subdomain));
        $cacheKey  = "landing_page:{$subdomain}";

        // ── Check tenant status before touching the cache ──────────────────
        $tenant = \App\Models\Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant) {
            return response()->json(['error' => 'School not found'], 404);
        }

        if ($tenant->status === 'provisioning') {
            return response()->json([
                '_provisioning' => true,
                'subdomain'     => $subdomain,
                'message'       => 'This school is being set up. Please check back in a few minutes.',
            ]);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                '_inactive' => true,
                'status'    => $tenant->status,
                'subdomain' => $subdomain,
                'message'   => $tenant->status === 'suspended'
                    ? 'This school account has been suspended.'
                    : 'This school is not currently active.',
            ], 403);
        }

        $data = Cache::remember($cacheKey, self::PUBLIC_CACHE_TTL, function () use ($subdomain, $tenant) {
            if (! $tenant) {
                return null;
            }

            // ── 2. Switch to tenant DB ──────────────────────────────────
            tenancy()->initialize($tenant);

            // ── 3. Load school + landing page settings in 2 queries ─────
            $school = School::first();

            $settings = DB::table('settings')
                ->select('key', 'value')
                ->where('category', 'landing_page')
                ->when($school, fn ($q) => $q->where('school_id', $school->id))
                ->get()
                ->pluck('value', 'key')
                ->toArray();

            tenancy()->end();

            if (! $school) {
                return null;
            }

            $templateId = $settings['template'] ?? 'classic';
            $template   = collect(self::TEMPLATES)->firstWhere('id', $templateId) ?? self::TEMPLATES[0];

            return [
                'school'   => [
                    'name'    => $school->name,
                    'logo'    => $school->logo,
                    'email'   => $school->email,
                    'phone'   => $school->phone,
                    'address' => $school->address,
                    'website' => $school->website,
                ],
                'template' => $template,
                'branding' => [
                    'primary_color'    => $settings['primary_color']    ?? $template['colors']['primary'],
                    'secondary_color'  => $settings['secondary_color']  ?? $template['colors']['secondary'],
                    'background_color' => $settings['background_color'] ?? $template['colors']['background'],
                    'text_color'       => $settings['text_color']       ?? $template['colors']['text'],
                    'custom_css'       => $settings['custom_css']       ?? null,
                ],
                'hero'     => [
                    'headline'    => $settings['hero_headline']    ?? $school->name,
                    'subheadline' => $settings['hero_subheadline'] ?? 'Excellence in Education',
                    'image_url'   => $settings['hero_image_url']   ?? null,
                    'video_url'   => $settings['hero_video_url']   ?? null,
                    'cta_text'    => $settings['hero_cta_text']    ?? 'Apply Now',
                    'cta_url'     => $settings['hero_cta_url']     ?? null,
                ],
                'about'    => [
                    'tagline'   => $settings['about_tagline']   ?? null,
                    'text'      => $settings['about_text']      ?? null,
                    'image_url' => $settings['about_image_url'] ?? null,
                    'vision'    => $settings['vision']          ?? null,
                    'mission'   => $settings['mission']         ?? null,
                ],
                'contact'  => [
                    'address'       => $settings['contact_address']  ?? $school->address,
                    'phone'         => $settings['contact_phone']    ?? $school->phone,
                    'email'         => $settings['contact_email']    ?? $school->email,
                    'map_embed_url' => $settings['map_embed_url']    ?? null,
                    'working_hours' => $settings['working_hours']    ?? 'Mon–Fri: 7:30 am – 3:30 pm',
                ],
                'social'   => [
                    'facebook'  => $settings['social_facebook']  ?? null,
                    'twitter'   => $settings['social_twitter']   ?? null,
                    'instagram' => $settings['social_instagram'] ?? null,
                    'youtube'   => $settings['social_youtube']   ?? null,
                    'linkedin'  => $settings['social_linkedin']  ?? null,
                ],
                'features' => json_decode(
                    $settings['enabled_features'] ?? json_encode($template['features']),
                    true
                ),
                'content'  => self::content($settings),
                'seo'      => [
                    'title'       => $settings['seo_title']       ?? $school->name,
                    'description' => $settings['seo_description'] ?? null,
                    'keywords'    => $settings['seo_keywords']    ?? null,
                    'og_image'    => $settings['seo_og_image']    ?? $school->logo,
                ],
            ];
        });

        if (! $data) {
            return response()->json(['error' => 'School not found'], 404);
        }

        return response()->json($data);
    }

    // -------------------------------------------------------------------------
    // Authenticated endpoints (tenant middleware + Sanctum)
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/schools/landing-page
     *
     * Returns the current school's landing page config for the admin editor.
     * Cached per-school for 5 minutes — invalidated on save.
     */
    public function show(Request $request): JsonResponse
    {
        $school   = $this->school($request);
        $cacheKey = "landing_admin:{$school?->id}";

        $payload = Cache::remember($cacheKey, 300, function () use ($school) {
            $settings = DB::table('settings')
                ->select('key', 'value')
                ->where('category', 'landing_page')
                ->where('school_id', $school?->id)
                ->get()
                ->pluck('value', 'key')
                ->toArray();

            $templateId = $settings['template'] ?? 'classic';
            $template   = collect(self::TEMPLATES)->firstWhere('id', $templateId) ?? self::TEMPLATES[0];

            return [
                'school'        => $school,
                'template'      => $template,
                'settings'      => $settings,
                'all_templates' => self::TEMPLATES,
            ];
        });

        return response()->json($payload);
    }

    /**
     * PUT /api/v1/schools/landing-page
     *
     * Accepts any subset of landing page keys (partial update).
     * Uses a single batch UPSERT instead of N individual queries.
     * Fires LandingPageUpdated event → queued cache invalidation.
     */
    public function update(Request $request): JsonResponse
    {
        // The editor sends back what show() gave it: enabled_features is stored (and
        // returned) as JSON text, so accept that as well as a real list.
        foreach (array_merge(['enabled_features'], self::CONTENT_LISTS) as $listKey) {
            if (is_string($request->input($listKey))) {
                $request->merge([$listKey => json_decode($request->input($listKey), true) ?: []]);
            }
        }

        $validator = Validator::make($request->all(), [
            'template'         => 'sometimes|nullable|string|in:' . implode(',', array_column(self::TEMPLATES, 'id')),
            'primary_color'    => 'sometimes|nullable|string|max:20',
            'secondary_color'  => 'sometimes|nullable|string|max:20',
            'background_color' => 'sometimes|nullable|string|max:20',
            'text_color'       => 'sometimes|nullable|string|max:20',
            'custom_css'       => 'sometimes|nullable|string|max:10000',
            'hero_headline'    => 'sometimes|nullable|string|max:255',
            'hero_subheadline' => 'sometimes|nullable|string|max:500',
            'hero_image_url'   => 'sometimes|nullable|string|max:2048',
            'hero_video_url'   => 'sometimes|nullable|string|max:2048',
            'hero_cta_text'    => 'sometimes|nullable|string|max:100',
            'hero_cta_url'     => 'sometimes|nullable|string|max:2048',
            'about_tagline'    => 'sometimes|nullable|string|max:500',
            'about_text'       => 'sometimes|nullable|string|max:5000',
            'about_image_url'  => 'sometimes|nullable|string|max:2048',
            'vision'           => 'sometimes|nullable|string|max:2000',
            'mission'          => 'sometimes|nullable|string|max:2000',
            'contact_address'  => 'sometimes|nullable|string|max:500',
            'contact_phone'    => 'sometimes|nullable|string|max:50',
            'contact_email'    => 'sometimes|nullable|email|max:255',
            'map_embed_url'    => 'sometimes|nullable|string|max:2048',
            'working_hours'    => 'sometimes|nullable|string|max:255',
            'social_facebook'  => 'sometimes|nullable|url|max:500',
            'social_twitter'   => 'sometimes|nullable|url|max:500',
            'social_instagram' => 'sometimes|nullable|url|max:500',
            'social_youtube'   => 'sometimes|nullable|url|max:500',
            'social_linkedin'  => 'sometimes|nullable|url|max:500',
            'enabled_features' => 'sometimes|array',
            ...array_fill_keys(self::CONTENT_LISTS, 'sometimes|nullable|array|max:60'),
            ...array_fill_keys(self::CONTENT_TEXTS, 'sometimes|nullable|string|max:20000'),
            'seo_title'        => 'sometimes|nullable|string|max:255',
            'seo_description'  => 'sometimes|nullable|string|max:500',
            'seo_keywords'     => 'sometimes|nullable|string|max:500',
            'seo_og_image'     => 'sometimes|nullable|string|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school   = $this->school($request);
        $schoolId = $school?->id;
        $data     = $validator->validated();

        // JSON-encode array values before storage
        foreach (array_merge(['enabled_features'], self::CONTENT_LISTS) as $listKey) {
            if (array_key_exists($listKey, $data)) {
                $data[$listKey] = json_encode(array_values((array) ($data[$listKey] ?? [])));
            }
        }

        // ── Batch UPSERT (1 query instead of N) ────────────────────────────
        // Uses the composite unique index on (school_id, key) added in the
        // 2026_04_11_000001 migration.
        $now  = now()->toDateTimeString();
        $rows = collect($data)
            ->map(fn ($value, $key) => [
                'key'        => $key,
                'school_id'  => $schoolId,
                'category'   => 'landing_page',
                'value'      => $value ?? '',
                'type'       => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::table('settings')->upsert(
            $rows,
            ['school_id', 'key'],  // unique key columns
            ['value', 'updated_at']        // columns to update on conflict
        );

        // ── Invalidate caches via queued event ─────────────────────────────
        $subdomain = $this->resolveSubdomain($request);
        event(new LandingPageUpdated($subdomain, $schoolId ?? 0));

        // Clear admin cache immediately (no need to queue this)
        Cache::forget("landing_admin:{$schoolId}");

        $updatedSettings = DB::table('settings')
            ->select('key', 'value')
            ->where('category', 'landing_page')
            ->where('school_id', $schoolId)
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        return response()->json([
            'message'  => 'Landing page updated successfully',
            'settings' => $updatedSettings,
        ]);
    }

    /**
     * POST /api/v1/schools/landing-page/upload-asset
     *
     * Upload a landing page image to S3 and persist the URL.
     * - hero_image  → hero_image_url  setting
     * - about_image → about_image_url setting
     * - logo        → updates School.logo column directly
     * - og_image    → seo_og_image setting
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // PDFs only for website content (admission form, brochure).
            'file' => $request->input('type') === 'content' ? 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:10240' : 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'type' => 'required|string|in:hero_image,about_image,logo,og_image,content,welcome_photo',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $school = $this->school($request);
        $type   = $request->input('type');

        $path = $request->file('file')->store(
            "schools/{$school?->id}/landing/{$type}",
            's3'
        );

        $url = Storage::disk('s3')->url($path);

        // ── Logo: update School model, not the settings table ───────────────
        if ($type === 'logo' && $school) {
            $school->update(['logo' => $url]);

            $subdomain = $this->resolveSubdomain($request);
            event(new LandingPageUpdated($subdomain, $school->id));

            return response()->json([
                'message' => 'Logo uploaded successfully',
                'url'     => $url,
                'key'     => 'logo',
            ], 201);
        }

        // ── Pictures for list items (news, staff, gallery…): the editor keeps the URL ──
        if ($type === 'content') {
            return response()->json(['message' => 'Uploaded', 'url' => $url, 'key' => null], 201);
        }

        // ── All other asset types → settings table ──────────────────────────
        $keyMap = [
            'hero_image'  => 'hero_image_url',
            'about_image' => 'about_image_url',
            'og_image'    => 'seo_og_image',
            'welcome_photo' => 'welcome_photo_url',
        ];

        $settingKey = $keyMap[$type] ?? "{$type}_url";
        $now        = now()->toDateTimeString();

        DB::table('settings')->upsert(
            [[
                'key'        => $settingKey,
                'school_id'  => $school?->id,
                'category'   => 'landing_page',
                'value'      => $url,
                'type'       => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['school_id', 'key'],
            ['value', 'updated_at']
        );

        // Invalidate public + admin caches
        $subdomain = $this->resolveSubdomain($request);
        event(new LandingPageUpdated($subdomain, $school?->id ?? 0));
        Cache::forget("landing_admin:{$school?->id}");

        return response()->json([
            'message' => 'Asset uploaded successfully',
            'url'     => $url,
            'key'     => $settingKey,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function school(Request $request): ?School
    {
        return $request->attributes->get('school') ?? School::first();
    }

    /**
     * Resolve subdomain from request context for cache key generation.
     * Tries: tenant attribute → X-Subdomain header → tenant()->getTenant('id').
     */
    private function resolveSubdomain(Request $request): string
    {
        return $request->attributes->get('tenant_id')
            ?? $request->header('X-Subdomain')
            ?? (function_exists('tenant') ? tenant()?->getTenantKey() : null)
            ?? 'unknown';
    }
}
