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
            'id'          => 'summit',
            'name'        => 'Summit',
            'description' => 'International boarding look — full-screen photo hero, overlay menu, quick-link CTA strip, programme overlays and partner logos. Inspired by leading Nigerian international schools.',
            'preview_url' => null,
            'colors'      => ['primary' => '#0a2e5c', 'secondary' => '#3db5e6', 'background' => '#ffffff', 'text' => '#1a2332'],
            'features'    => ['quick_links', 'programmes', 'stats_counter', 'welcome', 'gallery', 'news_section', 'testimonials', 'partners', 'cta_banner'],
        ],
        [
            'id'          => 'sunshine',
            'name'        => 'Sunshine',
            'description' => 'Warm cream and crimson with a photo slab hero, school-section cards, admission banner and achievement news. Friendly multi-campus family feel.',
            'preview_url' => null,
            'colors'      => ['primary' => '#b91c1c', 'secondary' => '#e8b923', 'background' => '#fffdf7', 'text' => '#2c2416'],
            'features'    => ['welcome', 'programmes', 'quick_links', 'cta_banner', 'news_section', 'stats_counter', 'gallery', 'testimonials'],
        ],
        [
            'id'          => 'shepherd',
            'name'        => 'Shepherd',
            'description' => 'Crimson condensed headings, diamond counters, staff portraits, key dates and facility feature cards — faith-forward excellence.',
            'preview_url' => null,
            'colors'      => ['primary' => '#c41e3a', 'secondary' => '#1a2744', 'background' => '#ffffff', 'text' => '#1f1f1f'],
            'features'    => ['welcome', 'stats_counter', 'events', 'staff', 'news_section', 'programmes', 'gallery', 'cta_banner'],
        ],
        [
            'id'          => 'prestige',
            'name'        => 'Prestige',
            'description' => 'British international heritage — cream canvas, elegant serif, split photography, values and generous whitespace.',
            'preview_url' => null,
            'colors'      => ['primary' => '#0b1f3a', 'secondary' => '#b8956c', 'background' => '#f7f3ec', 'text' => '#1c2433'],
            'features'    => ['welcome', 'programmes', 'stats_counter', 'gallery', 'testimonials', 'news_section', 'partners'],
        ],
        [
            'id'          => 'classic',
            'name'        => 'Classic',
            'description' => 'Traditional navy school site with contact top-bar, quick-links band, programmes and news — clear and professional.',
            'preview_url' => null,
            'colors'      => ['primary' => '#1a3a6b', 'secondary' => '#f0a202', 'background' => '#ffffff', 'text' => '#243044'],
            'features'    => ['quick_links', 'welcome', 'programmes', 'news_section', 'events', 'stats_counter', 'gallery', 'cta_banner'],
        ],
        [
            'id'          => 'minimal',
            'name'        => 'Minimal',
            'description' => 'Quiet Montessori / prep look — soft serif, feature icons, generous whitespace and calm photography.',
            'preview_url' => null,
            'colors'      => ['primary' => '#171717', 'secondary' => '#15803d', 'background' => '#ffffff', 'text' => '#404040'],
            'features'    => ['welcome', 'feature_icons', 'programmes', 'gallery', 'testimonials', 'cta_banner'],
        ],
        [
            'id'          => 'evergreen',
            'name'        => 'Evergreen',
            'description' => 'Green-campus boarding — approach cards, programme sections, brochure CTA and warm gallery.',
            'preview_url' => null,
            'colors'      => ['primary' => '#0d4f3c', 'secondary' => '#d97706', 'background' => '#fafaf7', 'text' => '#1f2a24'],
            'features'    => ['programmes', 'cta_banner', 'values', 'welcome', 'news_section', 'gallery', 'testimonials', 'stats_counter'],
        ],
        [
            'id'          => 'crimson',
            'name'        => 'Crimson',
            'description' => 'Bold multi-campus network — years badge, partners strip, quick links, news and portal band.',
            'preview_url' => null,
            'colors'      => ['primary' => '#b80000', 'secondary' => '#111111', 'background' => '#ffffff', 'text' => '#18181b'],
            'features'    => ['stats_counter', 'welcome', 'partners', 'programmes', 'quick_links', 'news_section', 'gallery', 'cta_banner'],
        ],
        [
            'id'          => 'heritage',
            'name'        => 'Heritage',
            'description' => 'British boarding house — burgundy and gold, centred crest hero, diamond stats, staff and partners.',
            'preview_url' => null,
            'colors'      => ['primary' => '#5c1a1b', 'secondary' => '#c9a227', 'background' => '#faf6f0', 'text' => '#2a1810'],
            'features'    => ['welcome', 'values', 'programmes', 'stats_counter', 'staff', 'gallery', 'testimonials', 'partners', 'cta_banner'],
        ],
    ];

    /** Templates removed because they repeated another: saved sites move to the closest one. */
    public const RETIRED_TEMPLATES = ['harbor' => 'summit', 'modern' => 'summit', 'vibrant' => 'crimson', 'academic' => 'prestige'];

    /** Lists the editor manages (stored as JSON text, returned decoded on the public site). */
    public const CONTENT_LISTS = ['stats', 'programmes', 'news', 'events', 'staff', 'gallery', 'testimonials', 'partners', 'quick_links', 'admission_steps', 'values',
        'hero_slides',      // extra banner slides after the main one
        'home_sections',    // [{key, enabled, title}] — order, on/off and heading of each home section
    ];

    /** Inner pages that get their own banner (picture, title, line of text). */
    public const BANNER_PAGES = ['about', 'academics', 'admissions', 'news', 'gallery', 'contact', 'apply', 'login', 'careers'];

    /** Free-text content used by the inner pages. */
    public const CONTENT_TEXTS = ['announcement', 'motto', 'founded_year', 'welcome_title', 'welcome_text', 'welcome_name', 'welcome_role', 'welcome_photo_url',
        'history_text', 'academics_text', 'admissions_text', 'brochure_url', 'admission_form_url', 'gallery_text'];

    /** Text keys: the free texts plus each page banner's url / title / text. */
    public static function textKeys(): array
    {
        $keys = self::CONTENT_TEXTS;
        foreach (self::BANNER_PAGES as $p) {
            array_push($keys, "{$p}_banner_url", "{$p}_banner_title", "{$p}_banner_text");
        }

        return $keys;
    }

    /**
     * Facts the school's Compasse account already holds, so a new website is full on day one:
     * size (students / teachers / classes), its school sections (from the class list), the
     * term dates and school-wide events. Nothing private: only events meant for everyone.
     */
    public static function autoContent(): array
    {
        $safe = function (callable $fn, $default) {
            try {
                return $fn();
            } catch (\Throwable) {
                return $default;
            }
        };
        $has = fn (string $t) => \Illuminate\Support\Facades\Schema::hasTable($t);

        $counts = [
            'students' => $safe(fn () => $has('students') ? DB::table('students')->count() : 0, 0),
            'teachers' => $safe(fn () => $has('teachers') ? DB::table('teachers')->count() : 0, 0),
            'classes' => $safe(fn () => $has('classes') ? DB::table('classes')->count() : 0, 0),
        ];

        // School sections from the class list (name, level and section type).
        $order = ['creche' => 0, 'nursery' => 1, 'primary' => 2, 'junior' => 3, 'senior' => 4];
        $titles = ['creche' => 'Crèche & Toddlers', 'nursery' => 'Nursery & Reception', 'primary' => 'Primary School', 'junior' => 'Junior Secondary', 'senior' => 'Senior Secondary'];
        $sections = [];
        foreach ($safe(fn () => $has('classes') ? DB::table('classes')->get(['name', 'level', 'section_type']) : collect(), collect()) as $c) {
            $hay = strtolower(($c->section_type ?? '') . ' ' . ($c->level ?? '') . ' ' . $c->name);
            $key = match (true) {
                (bool) preg_match('/cr[eè]che|toddler|infant|playgroup|day ?care/', $hay) => 'creche',
                (bool) preg_match('/nursery|reception|kindergarten|\bkg\b|pre-?school|pre-?k|early ?years|montessori/', $hay) => 'nursery',
                (bool) preg_match('/senior|\bss\s?\d|\bsss|\bss[123]\b|year (10|11|12|ten|eleven|twelve)|grade (10|11|12)|a.?level|sixth/', $hay) => 'senior',
                (bool) preg_match('/junior|\bjss|\bjs\s?\d|year (7|8|9|seven|eight|nine)|grade (7|8|9)/', $hay) => 'junior',
                (bool) preg_match('/primary|basic|year|grade|class \d|elementary/', $hay) => 'primary',
                default => null,
            };
            if ($key) {
                $sections[$key]['key'] = $key;
                $sections[$key]['title'] = $titles[$key];
                $sections[$key]['classes'][] = $c->name;
            }
        }
        uasort($sections, fn ($a, $b) => $order[$a['key']] <=> $order[$b['key']]);

        // Key dates: upcoming term starts / ends within a year.
        $today = now()->toDateString();
        $dates = [];
        foreach ($safe(fn () => $has('terms') ? DB::table('terms')->whereNotNull('start_date')->orderBy('start_date')->get(['name', 'start_date', 'end_date']) : collect(), collect()) as $t) {
            foreach ([['start_date', 'begins'], ['end_date', 'ends']] as [$col, $word]) {
                $d = $t->{$col} ? substr((string) $t->{$col}, 0, 10) : null;
                if ($d && $d >= $today && $d <= now()->addYear()->toDateString()) {
                    $dates[] = ['title' => "{$t->name} {$word}", 'date' => $d];
                }
            }
        }
        usort($dates, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // School-wide events (audience "all", not tied to a class).
        $events = $safe(fn () => $has('events') ? DB::table('events')
            ->where(fn ($q) => $q->whereNull('target_audience')->orWhere('target_audience', 'all'))
            ->whereNull('class_id')
            ->where('start_date', '>=', now()->subDays(14)->toDateString())
            ->orderBy('start_date')->limit(6)
            ->get(['title', 'description', 'start_date', 'start_time', 'end_time', 'location'])
            ->map(fn ($e) => [
                'title' => $e->title, 'text' => $e->description, 'date' => substr((string) $e->start_date, 0, 10),
                'time' => trim(($e->start_time ? substr($e->start_time, 0, 5) : '') . ($e->end_time ? ' – ' . substr($e->end_time, 0, 5) : '')) ?: null,
                'venue' => $e->location,
            ])->values()->all() : [], []);

        return ['counts' => $counts, 'sections' => array_values($sections), 'dates' => array_slice($dates, 0, 6), 'events' => $events];
    }

    /** Decoded lists + texts for the public site (only what the school entered). */
    public static function content(array $settings): array
    {
        $out = [];
        foreach (self::CONTENT_LISTS as $k) {
            $v = json_decode((string) ($settings[$k] ?? '[]'), true);
            $out[$k] = is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        }
        foreach (self::textKeys() as $k) {
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
        $templates = Cache::remember('landing:templates:v3', self::TEMPLATES_CACHE_TTL, fn () => self::TEMPLATES);

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

            $auto = $school ? self::autoContent() : [];

            tenancy()->end();

            if (! $school) {
                return null;
            }

            $templateId = $settings['template'] ?? 'classic';
            $templateId = self::RETIRED_TEMPLATES[$templateId] ?? $templateId;
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
                'auto'     => $auto,
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
            $templateId = self::RETIRED_TEMPLATES[$templateId] ?? $templateId;
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
            ...array_fill_keys(self::textKeys(), 'sometimes|nullable|string|max:20000'),
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
    /** The public cache is keyed by subdomain (see publicLandingPage), not the tenant id. */
    private function resolveSubdomain(Request $request): string
    {
        $sub = (function_exists('tenant') ? tenant()?->subdomain : null)
            ?? $request->header('X-Subdomain')
            ?? 'unknown';
        // Clear it now as well as via the queued listener, outside the tenant's cache scope.
        try {
            tenancy()->central(fn () => Cache::forget("landing_page:{$sub}"));
        } catch (\Throwable $e) {
            report($e);
        }

        return $sub;
    }
}
