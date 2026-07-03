<?php

namespace App\Services\OpenAI;

use OpenAI;
use Illuminate\Support\Facades\Log;

class ListingCommandService
{
    protected $client;
    protected array $taxonomy;

    /**
     * Bidirectional alias groups for PH landmarks and well-known locations.
     * Members in a group are treated as interchangeable when matching a title
     * against the listing context — so "MOA" in a title earns landmark credit
     * even when the stored facility name is "SM Mall of Asia". Keep the list
     * short and only add aliases that have HIGH search volume; over-aliasing
     * dilutes the signal and risks false positives on short codes.
     */
    protected const ENTITY_ALIASES = [
        // Landmarks
        ['mall of asia', 'sm mall of asia', 'moa'],
        ['bonifacio global city', 'bgc', 'fort bonifacio', 'the fort'],
        ['metro rail transit', 'mrt-3', 'mrt 3', 'mrt3'],
        ['light rail transit', 'lrt-1', 'lrt 1', 'lrt-2', 'lrt 2'],
        ['ninoy aquino international airport', 'naia', 'manila airport'],
        ['ayala center cebu', 'ayala center'],
        ['it park cebu', 'cebu it park'],
        ['cebu business park', 'business park cebu'],
        ['ortigas center', 'ortigas cbd'],
        ['greenbelt mall', 'greenbelt'],
        ['glorietta mall', 'glorietta'],
        // Property type abbreviations — LLMs use these in titles for SEO/
        // brevity; without aliasing, "Condo" in a title fails to match
        // "Condominium" in the context and drops 10pts from keyword_match.
        ['condominium', 'condo', 'condos'],
        ['townhouse', 'town house'],
        ['apartment', 'apt'],
        ['house and lot', 'house & lot'],
        ['residential lot'],
        ['commercial lot'],
        ['1 bedroom', '1br', '1-br', 'one bedroom'],
        ['2 bedrooms', '2br', '2-br', 'two bedroom'],
        ['3 bedrooms', '3br', '3-br', 'three bedroom'],
        ['4 bedrooms', '4br', '4-br', 'four bedroom'],
    ];

    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.key'));

        $this->taxonomy = [
            'categories' => ["For Sale", "For Rent", "Foreclosure"],
            'types' => [
                ["id"=>1,"name"=>"Condominium"],
                ["id"=>2,"name"=>"House"],
                ["id"=>3,"name"=>"Land"],
                ["id"=>4,"name"=>"Commercial"],
            ],
            'subtypes' => [
                ["id"=>1,"name"=>"Apartment","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>2,"name"=>"Townhouse","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>3,"name"=>"House and Lot","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>7,"name"=>"Boarding House","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>9,"name"=>"Penthouse","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>10,"name"=>"Retirement House","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>11,"name"=>"Studio","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>13,"name"=>"Warehouse","type"=>["id"=>4,"name"=>"Commercial"]],
                ["id"=>17,"name"=>"Agricultural Lot","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>23,"name"=>"BPO","type"=>["id"=>4,"name"=>"Commercial"]],
                ["id"=>28,"name"=>"Pension House","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>30,"name"=>"Office","type"=>["id"=>4,"name"=>"Commercial"]],
                ["id"=>31,"name"=>"Island","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>34,"name"=>"1 Bedroom","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>35,"name"=>"2 Bedrooms","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>38,"name"=>"3 Bedrooms","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>39,"name"=>"4 Bedrooms","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>40,"name"=>"Loft","type"=>["id"=>1,"name"=>"Condominium"]],
                ["id"=>42,"name"=>"Beach House / Resort","type"=>["id"=>2,"name"=>"House"]],
                ["id"=>43,"name"=>"Residential Lot","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>44,"name"=>"Commercial Lot","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>45,"name"=>"Memorial","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>46,"name"=>"Beach Lot","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>47,"name"=>"Building","type"=>["id"=>4,"name"=>"Commercial"]],
                ["id"=>48,"name"=>"Industrial Lot","type"=>["id"=>3,"name"=>"Land"]],
                ["id"=>49,"name"=>"Hotel","type"=>["id"=>4,"name"=>"Commercial"]],
                ["id"=>50,"name"=>"Space","type"=>["id"=>4,"name"=>"Commercial"]],
            ],
            'furnishings' => [
                ["id"=>1,"name"=>"Fully Furnished"],
                ["id"=>2,"name"=>"Semi Furnished"],
                ["id"=>3,"name"=>"Unfurnished"],
                ["id"=>4,"name"=>"Finish"],
            ],
        ];
    }

    public function parseListingQuery(string $query): ?array
    {
        $prompt = <<<PROMPT
        Extract structured real estate filters from the user query.
        Use the taxonomy provided. 
        Rules:
        1. Always return a function call with structured data.
        2. Include 'categories', 'types', 'subtypes', and 'furnishings'.
        3. If the query mentions a type but no subtype, return all subtypes associated with that type.
        PROMPT;
        
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'query' => $query,
                        'taxonomy' => $this->taxonomy
                    ])
                ],
            ],
            'functions' => [$this->getToolDefinition()],
            'function_call' => ['name' => 'parse_listing_query'],
        ]);

        $this->logUsage('parse_query', $response);
        $data = $this->extractToolResponse($response);
    
        if ($data) {
            // Ensure subtypes exist for mentioned types if missing
            if (!empty($data['types']) && empty($data['subtypes'])) {
                $selectedTypeIds = array_map(fn($t) => $t['id'], $data['types']);
                $data['subtypes'] = array_values(array_filter($this->taxonomy['subtypes'], function($subtype) use ($selectedTypeIds) {
                    return in_array($subtype['type']['id'], $selectedTypeIds);
                }));
            }
    
            return $this->normalize($data);
        }
    
        return null;
    }

    public function classifyImages(array $photos)
    {
        $prompt = <<<PROMPT
        You are an AI assistant that evaluates real estate listing images.

        Your task:
        1. Classify each image into ONE of the following:
        - Bad
        - Good
        - Excellent

        Definitions:
        - Bad:
        Blurry, dark, low quality, duplicate, irrelevant, messy, obstructed, or not useful for marketing.

        - Good:
        Clear and usable images of the property. Shows rooms or features but may lack strong composition or lighting.

        - Excellent:
        High-quality, well-lit, professionally composed images. Visually appealing and ideal for marketing.

        2. ONLY for Good and Excellent images:
        Generate a SHORT keyword-based description.

        Description rules:
        - Use short phrases only (NOT full sentences)
        - Focus on visible features (e.g., "modern kitchen", "spacious living room", "near beach", "city view", "bright bedroom")
        - Do NOT include filler words
        - Do NOT describe Bad images
        - Keep it concise (3–6 words max)

        Output format:
        Return function call ONLY with structured JSON.
        PROMPT;
        
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Classify the following real estate images.'
                        ],
                        ...array_map(function ($photo, $index) {
                            return [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $photo['url'],
                                ],
                            ];
                        }, $photos, array_keys($photos))
                    ]
                ],
            ],
            'functions' => [$this->getImageClassificationToolDefinition()],
            'function_call' => [
                'name' => 'classify_image_with_description'
            ],
        ]);

        $this->logUsage('classify_images', $response);
        return $this->extractToolResponse($response);
    }

    /**
     * Deterministic feature extractor — runs PURELY in PHP against the title
     * string + listing context. The LLM is no longer trusted to grade itself;
     * it only writes titles. PHP detects every feature via regex / substring
     * match and validates location + landmark + project against the context
     * (rejecting hallucinated mentions that aren't actually in the listing's
     * data).
     *
     * Returned feature flags drive scoreFromFeatures() below.
     */
    protected function extractTitleFeatures(string $title, array $context): array
    {
        $lower = mb_strtolower(trim($title));
        $wordCount = $title === '' ? 0 : count(array_filter(preg_split('/\s+/', trim($title))));

        // Transaction intent: capture WHICH phrase was matched so the scorer
        // can verify it against the listing's actual category. Wrong intent
        // (e.g. "For Rent" on a sale listing) should be penalized harder
        // than no intent at all.
        // Recognized intents include the category-scoped substitutes the
        // agent may deliberately use (sale: Rush Sale / For Assume /
        // Sacrifice Sale; rent: For Lease / Rent to Own) and the universal
        // one (Ready for Occupancy) — valid intent that must not be
        // penalized when it matches the listing's category.
        $matchedIntentPhrase = null;
        if (preg_match('/\b(for sale|for rent|foreclosure|rush sale|for assume|sacrifice sale|for lease|rent[\s-]?to[\s-]?own|ready for occupancy)\b/i', $title, $m)) {
            // Normalize spacing/hyphens so "rent-to-own" and "rent to own"
            // compare equal downstream.
            $matchedIntentPhrase = preg_replace('/[\s-]+/', ' ', mb_strtolower($m[1]));
        }

        // Land detection: land/lot listings have no bedrooms, so the bedroom
        // axis is N/A for them (lot size carries the spec weight instead — see
        // scoreFromFeatures). Detect via property_type "Land" or any land
        // subtype (the "Lot" subtypes, plus Island/Memorial which omit "Lot").
        $ctxType    = mb_strtolower((string) ($context['property_type'] ?? ''));
        $ctxSubtype = mb_strtolower((string) ($context['property_subtype'] ?? ''));
        $isLand = str_contains($ctxType, 'land')
            || str_contains($ctxSubtype, 'lot')
            || in_array($ctxSubtype, ['island', 'memorial'], true);

        // Bedroom count: "3BR", "3 BR", "3-bedroom", "2 bedroom", etc.
        // "Studio" also counts — Studio listings have no bedrooms by
        // definition, so mentioning "Studio" satisfies the SEO axis the
        // same way a bedroom count does (it tells the buyer the unit size).
        $hasBedroom = (bool) preg_match('/\b\d+\s*[- ]?(br|bedroom|-bedroom)\b/i', $title)
            || (bool) preg_match('/\bstudio\b/i', $title);

        // Property type/subtype must match context — protects against fluff.
        // Routes through titleMatchesEntity so abbreviations are credited
        // ("Condo" matches context "Condominium", "3BR" matches "3 Bedrooms").
        $hasType = false;
        foreach (['property_type', 'property_subtype'] as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && $value !== '' && $this->titleMatchesEntity($lower, $value)) {
                $hasType = true;
                break;
            }
        }

        // Size quantifier: "65 sqm", "65sqm", "65 sq m", "65m²".
        $hasSize = (bool) preg_match('/\b\d+(\.\d+)?\s*(sqm|sq\.?\s?m|m²)\b/i', $title);

        // Location hierarchy: collect EVERY level that matches so the scorer
        // can reward titles that stack multiple specificities. "Talamban,
        // Cebu City" stacks barangay + city → small bonus over plain "Cebu".
        // Aliases handled by titleMatchesEntity.
        $locationSignals = [];
        $matchedLocation = null;
        foreach (['barangay', 'city', 'province'] as $level) {
            $value = $context[$level] ?? null;
            if (is_string($value) && $value !== '' && $this->titleMatchesEntity($lower, $value)) {
                $locationSignals[] = $level;
                if ($matchedLocation === null) $matchedLocation = $value;
            }
        }
        // Address fallback — only if no structured location matched.
        if (empty($locationSignals) && !empty($context['address']) && is_string($context['address'])) {
            $tokens = array_filter(array_map('trim', explode(',', $context['address'])));
            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 4 && mb_stripos($lower, mb_strtolower($token)) !== false) {
                    $locationSignals[] = 'address';
                    $matchedLocation = $token;
                    break;
                }
            }
        }

        // Landmark — STRICT validation against context.nearby_facilities,
        // now via titleMatchesEntity so MOA/BGC-style aliases are credited.
        $matchedLandmark = null;
        $nearby = $context['nearby_facilities'] ?? null;
        if (is_array($nearby)) {
            foreach ($nearby as $items) {
                if (!is_array($items)) continue;
                foreach ($items as $item) {
                    $name = is_array($item) ? ($item['name'] ?? null) : null;
                    if (!is_string($name) || $name === '') continue;
                    if ($this->titleMatchesEntity($lower, $name)) {
                        $matchedLandmark = $name;
                        break 2;
                    }
                }
            }
        }

        // Project name — strict context match (with alias support).
        $hasProject = false;
        $projectName = $context['project_name'] ?? null;
        if (is_string($projectName) && $projectName !== '' && $this->titleMatchesEntity($lower, $projectName)) {
            $hasProject = true;
        }

        // Photo keywords + amenities — both arrays of strings from context.
        $hasPhotoKeyword = $this->titleContainsAny($lower, $context['photo_keywords'] ?? null);
        $hasAmenity = $this->titleContainsAny($lower, $context['amenities'] ?? null);

        // Query-pattern alignment — does this title READ like a real Google
        // query? Captures common PH search shapes: "for sale in X",
        // "3BR condo near Y", "house for rent in Z", "near <landmark>".
        // Orthogonal to individual feature flags above: those check keyword
        // PRESENCE; this checks whether keywords combine into a query phrase.
        $hasQueryPattern = (bool) preg_match(
            '/(\bfor\s+(sale|rent|foreclosure)\s+(in|near|at)\b)'
            . '|(\b\d+\s*br\s+(condo|house|townhouse|apartment|unit|studio))'
            . '|(\b(condo|house|lot|townhouse|apartment|villa|studio)\s+(for\s+(sale|rent)|near|in|at)\b)'
            . '|(\bnear\s+[a-z])/i',
            $title
        );

        // Spam signals — caught later to apply a hard penalty.
        $isAllCaps = preg_match('/[A-Z]{4,}/', $title) && !preg_match('/[a-z]/', $title);
        $hasExclamation = (bool) preg_match('/!/', $title);

        return [
            'word_count'             => $wordCount,
            'has_transaction_intent' => $matchedIntentPhrase !== null,
            'matched_intent_phrase'  => $matchedIntentPhrase, // 'for sale'|'for rent'|'foreclosure'|null
            'has_bedroom_count'      => $hasBedroom,
            'is_land'                => $isLand,
            'has_property_type'      => $hasType,
            'has_size_quantifier'    => $hasSize,
            'location_signals'       => array_values(array_unique($locationSignals)),
            'matched_location'       => $matchedLocation,
            'matched_landmark'       => $matchedLandmark,    // string|null — validated against context
            'has_project_name'       => $hasProject,
            'has_photo_keyword'      => $hasPhotoKeyword,
            'has_amenity_keyword'    => $hasAmenity,
            'has_query_pattern'      => $hasQueryPattern,
            'is_all_caps'            => (bool) $isAllCaps,
            'has_exclamation'        => $hasExclamation,
        ];
    }

    /**
     * Helper: does the lowercased title contain ANY of the given strings?
     * Used for photo-keyword + amenity presence checks.
     */
    protected function titleContainsAny(string $lowerTitle, mixed $candidates): bool
    {
        if (!is_array($candidates)) return false;
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') continue;
            if (mb_strlen($candidate) < 3) continue;
            if (mb_stripos($lowerTitle, mb_strtolower($candidate)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the (lower-cased) title contain $name OR any of its known aliases?
     * Used for landmark, project, and structured location matches so the
     * scorer credits semantic equivalents (MOA ↔ Mall of Asia ↔ SM Mall of
     * Asia) instead of failing on the exact-string check. Short aliases
     * (≤4 chars) require word boundaries to avoid false positives like
     * "MOA" inside "Moana" or "BGC" inside a random token.
     */
    protected function titleMatchesEntity(string $lowerTitle, string $name): bool
    {
        if ($name === '') return false;
        foreach ($this->expandAliases($name) as $candidate) {
            $len = mb_strlen($candidate);
            if ($len < 3) continue;
            if ($len <= 4) {
                if (preg_match('/\b' . preg_quote($candidate, '/') . '\b/iu', $lowerTitle)) return true;
            } else {
                if (mb_stripos($lowerTitle, $candidate) !== false) return true;
            }
        }
        return false;
    }

    /**
     * Expand a context entity name to all known aliases. Looks up ENTITY_ALIASES
     * with substring matching so "SM Mall of Asia" still picks up the "Mall of
     * Asia / MOA" group. Returned candidates are all lower-cased and unique.
     */
    protected function expandAliases(string $name): array
    {
        $lower = mb_strtolower(trim($name));
        if ($lower === '') return [];
        $candidates = [$lower];

        // Auto-alias for PH cities: "Cebu City" → also match "Cebu". Drop the
        // "City" suffix when the base token is ≥4 chars (skips ambiguous
        // 1–3 char prefixes). Lets titles that omit "City" still earn the
        // location signal — major PH cities are routinely abbreviated this
        // way in listings ("Condo for sale in Cebu" instead of "Cebu City").
        if (preg_match('/^(.+)\s+city$/i', $lower, $m)) {
            $base = trim($m[1]);
            if (mb_strlen($base) >= 4) $candidates[] = $base;
        }

        foreach (self::ENTITY_ALIASES as $group) {
            $matched = false;
            foreach ($group as $member) {
                $ml = mb_strtolower($member);
                if ($ml === $lower || mb_stripos($lower, $ml) !== false || mb_stripos($ml, $lower) !== false) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                foreach ($group as $g) $candidates[] = mb_strtolower($g);
            }
        }
        return array_values(array_unique($candidates));
    }

    /**
     * Deterministic SEO scoring. Pure PHP — no LLM input. Same title + same
     * context always produces the same score across runs.
     *
     * Axes (max 100 total — calibrated so "good" titles cluster 85-95,
     * "perfect" titles hit 100):
     *   keyword_match        0–35  intent(10) + type(10) + bedroom(10) + size(5)
     *                              Size is a bonus, not a core requirement —
     *                              most well-written titles omit it for length.
     *   location_strength    0–30  max specificity (barangay 30 / city 20 /
     *                              province 10 / address 8) + 5pt bonus when
     *                              2+ levels stack — capped at 30.
     *   intent_clarity       0–20  matches context category (20) > no context
     *                              to verify against (15) > intent absent (8) >
     *                              wrong intent (5).
     *   rich_entity_density  0–15  landmark(5) + project(4) + photo(2) +
     *                              amenity(1) + query-pattern bonus(5) —
     *                              capped at 15. Higher cap so titles with
     *                              landmark + query phrasing aren't penalized
     *                              just because they can't also include a
     *                              project or photo keyword.
     *
     * Word-count penalty (softened for PH place names): 9-13 = full, 7-15 =
     * -5%, 5-18 = -15%, else -30%. Hard cap: ALL CAPS or "!" multiplies by 0.6.
     *
     * @return array{score:int, score_breakdown:array<string,int>}
     */
    protected function scoreFromFeatures(array $features, array $context): array
    {
        $breakdown = [
            'keyword_match'       => 0,
            'location_strength'   => 0,
            'intent_clarity'      => 0,
            'rich_entity_density' => 0,
        ];

        // keyword_match: intent/type = 10 each (core). Bedroom = 10 + size = 5
        // for dwellings. For LAND, bedrooms are N/A — lot size carries the spec
        // weight instead (size worth 15) so a correct land title (no bedroom)
        // isn't penalized and stays able to reach the same max.
        if ($features['has_transaction_intent']) $breakdown['keyword_match'] += 10;
        if ($features['has_property_type'])      $breakdown['keyword_match'] += 10;
        if (!empty($features['is_land'])) {
            if ($features['has_size_quantifier']) $breakdown['keyword_match'] += 15;
        } else {
            if ($features['has_bedroom_count'])   $breakdown['keyword_match'] += 10;
            if ($features['has_size_quantifier']) $breakdown['keyword_match'] += 5;
        }

        // location_strength: highest specificity wins, plus a small stacking
        // bonus when the title cites multiple levels (e.g., barangay + city).
        $signals = (array) ($features['location_signals'] ?? []);
        $base = match (true) {
            in_array('barangay', $signals, true) => 30,
            in_array('city', $signals, true)     => 20,
            in_array('province', $signals, true) => 10,
            in_array('address', $signals, true)  => 8,
            default                              => 0,
        };
        $stackBonus = count(array_unique($signals)) >= 2 ? 5 : 0;
        $breakdown['location_strength'] = min(30, $base + $stackBonus);

        // intent_clarity: verify the matched intent phrase against the
        // listing's actual category. Wrong intent is worse than missing intent
        // because it actively misleads searchers.
        $titleIntent  = $features['matched_intent_phrase'] ?? null;
        $ctxCategory  = mb_strtolower((string) ($context['category'] ?? ''));
        $contextIntent = match (true) {
            str_contains($ctxCategory, 'sale')        => 'for sale',
            str_contains($ctxCategory, 'rent')        => 'for rent',
            str_contains($ctxCategory, 'foreclosure') => 'foreclosure',
            default                                   => '',
        };
        // Category-scoped substitute intents: full credit only when the
        // substitute belongs to the listing's category (a "For Lease" title
        // on a For Sale listing is a mismatch, same as "For Rent" would be).
        // "Ready for Occupancy" is sale-side: valid everywhere except rentals.
        $saleSubstitutes = ['rush sale', 'for assume', 'sacrifice sale'];
        $rentSubstitutes = ['for lease', 'rent to own'];
        if ($titleIntent === null) {
            $breakdown['intent_clarity'] = 8;
        } elseif ($titleIntent === 'ready for occupancy') {
            $breakdown['intent_clarity'] = $contextIntent === 'for rent' ? 5 : 20;
        } elseif (in_array($titleIntent, $saleSubstitutes, true)) {
            $breakdown['intent_clarity'] = ($contextIntent === '' || $contextIntent === 'for sale') ? 20 : 5;
        } elseif (in_array($titleIntent, $rentSubstitutes, true)) {
            $breakdown['intent_clarity'] = ($contextIntent === '' || $contextIntent === 'for rent') ? 20 : 5;
        } elseif ($contextIntent === '') {
            $breakdown['intent_clarity'] = 15;
        } elseif ($titleIntent === $contextIntent) {
            $breakdown['intent_clarity'] = 20;
        } else {
            $breakdown['intent_clarity'] = 5;
        }

        // rich_entity_density: landmark > project > query-pattern > photo >
        // amenity. Cap 15 — gives titles with landmark + query phrasing room
        // to score high even when no project/photo/amenity is available.
        if ($features['matched_landmark'])      $breakdown['rich_entity_density'] += 5;
        if ($features['has_project_name'])      $breakdown['rich_entity_density'] += 4;
        if (!empty($features['has_query_pattern'])) $breakdown['rich_entity_density'] += 5;
        if ($features['has_photo_keyword'])     $breakdown['rich_entity_density'] += 2;
        if ($features['has_amenity_keyword'])   $breakdown['rich_entity_density'] += 1;
        $breakdown['rich_entity_density'] = min(15, $breakdown['rich_entity_density']);

        $raw = array_sum($breakdown);

        // Word-count penalty — softened for PH where project + place names eat
        // 3-4 words easily. Reward 9-13 (sweet spot), tolerate 5-18, only
        // ding titles that are truly off (too short / spammy long).
        $wc = (int) ($features['word_count'] ?? 0);
        $penalty = match (true) {
            $wc >= 9 && $wc <= 13  => 1.0,
            $wc >= 7 && $wc <= 15  => 0.95,
            $wc >= 5 && $wc <= 18  => 0.85,
            default                => 0.7,
        };

        // Spam-signal hard penalty.
        if ($features['is_all_caps'] || $features['has_exclamation']) {
            $penalty *= 0.6;
        }

        $score = (int) round($raw * $penalty);
        return [
            'score'           => max(0, min(100, $score)),
            'score_breakdown' => $breakdown,
        ];
    }

    /**
     * Shared post-processing pipeline for both analyzeTitle and suggestTitles.
     * Pure PHP scoring is applied to every suggestion against the listing
     * context. Drops any LLM-supplied score / score_breakdown — the PHP-
     * computed values overwrite them.
     */
    protected function processSuggestions(?array $result, array $context): ?array
    {
        if (!is_array($result) || empty($result['suggestions']) || !is_array($result['suggestions'])) {
            return $result;
        }
        foreach ($result['suggestions'] as $i => $suggestion) {
            if (!is_array($suggestion)) continue;
            $title = (string) ($suggestion['title'] ?? '');
            $features = $this->extractTitleFeatures($title, $context);
            $scoring = $this->scoreFromFeatures($features, $context);
            $result['suggestions'][$i] = array_merge($suggestion, [
                'score'           => $scoring['score'],
                'score_breakdown' => $scoring['score_breakdown'],
            ]);
        }
        return $result;
    }

    public function analyzeTitle(string $title, array $context = []): ?array
    {
        $contextBlock = $this->buildListingContextBlock($context);

        $prompt = <<<PROMPT
        You are an SEO writer for Philippine real estate listings. Do two things: (a) give 2–3 sentences of specific, actionable feedback on the INPUT title (missing intent/keywords/location, fluff, or spam signals), and (b) write exactly 3 stronger alternatives. PHP scores titles deterministically (0–100) — aim for 85+ on every suggestion. Do NOT output any scores or ratings, only text.

        MANDATORY — every suggestion needs ALL FOUR or it scores low:
        1. Transaction intent — the EXACT phrase from "Listing category" ("For Sale", "For Rent", or "Foreclosure"; ~25 pts). The intent phrase MUST be the FIRST words of every suggestion (e.g. "For Sale: …") — never buried mid-title. Never omit; never substitute the property type ("Condominium:") for intent. PREFERRED INTENT OVERRIDE: if the context includes a "Preferred intent phrase" (an agent-chosen substitute — For Sale listings may use "Rush Sale"/"For Assume"/"Sacrifice Sale"; For Rent listings may use "For Lease"/"Rent to Own"; For Sale and Foreclosure listings may also use "Ready for Occupancy" — never on For Rent), then use THAT exact phrase as the leading intent of ALL 3 suggestions instead of the category phrase — RESPECT it, never flag it as wrong/missing, never replace it with the system category.
        2. Property keyword — use the MOST-SEARCHED term for the type: Condominium → "Condo" + the bedroom count (subtype "2 Bedrooms" → "2BR Condo"; subtype Studio/Penthouse/Loft → that word); House → the SUBTYPE (Townhouse, House and Lot, Apartment, Beach House), NOT the bare word "House"; Commercial → the SUBTYPE (Warehouse, Office, Building, Hotel); Land → SUBTYPE only, never "Land" (see LAND LISTINGS). Abbreviations ok.
        3. Most specific location — barangay AND city when both are available, else city alone.
        4. Bedroom count ("NBR"/"N-Bedroom") when context provides bedrooms — DWELLINGS ONLY (see LAND LISTINGS).

        LAND LISTINGS (when Property type is "Land"): do NOT include any bedroom count, even if context shows a bedroom value — land has no bedrooms. The property keyword MUST be the SUBTYPE alone — NEVER append the word "Land" (that is the type, not a keyword). So write "Island" (NOT "Island Land"), "Memorial Lot" (write "Memorial" as "Memorial Lot"), and every other land subtype verbatim since it already contains "Lot" (Residential Lot, Commercial Lot, Agricultural Lot, Beach Lot, Industrial Lot). Lot/floor area ("N sqm") is the headline spec for land — always include it when context provides it.

        BONUSES (include when they fit naturally): size "N sqm" (only if context has floor/lot area); a landmark from the "Nearby:" line, verbatim; project name verbatim; query-shape phrasing that reads like a real Google search ("for sale in Mandaue", "3BR condo near Mandani Bay").

        HALLUCINATION GUARD: never invent a location, landmark, or project. If none is provided, say so in FEEDBACK but do NOT add a guessed one to the suggestions (the validator strips invented values). When provided, use them verbatim.

        LENGTH & TONE: 9–13 words ideal (up to 18 if PH names force it, ~75 char soft cap). No ALL CAPS, "!", emojis, or filler ("dream home", "must see", "amazing", "beautiful", "rare opportunity").

        THREE VARIATIONS — each hits all four mandatory points, targets a DIFFERENT angle, and includes at least one fact the other two omit (not word-shuffles; if two share >70% of words, rewrite):
        1. Keyword/feature-led: "For [Sale/Rent/Foreclosure]: NBR [Subtype] in [Barangay], [City] — [Size or Quantified Feature]" — pack quantified specs (size, parking, RFO year); most likely to rank for direct "[NBR] [type] for sale in [city]" queries.
        2. Landmark/lifestyle-led: "For [Sale/Rent]: NBR [Subtype] Near [Landmark], [City] — [Photo Keyword or Amenity]" — must include a photo keyword/amenity the others omit; if no landmark, anchor on a unique amenity.
        3. Project/buyer-benefit-led: "For [Sale/Rent]: [Project] [Subtype] in [Barangay], [City] — [Buyer Persona Hook]" — target a persona (investor, family, professional, retiree) the others don't; if no project, use a size-led form.

        {$contextBlock}

        Return the function call. If the runtime cannot execute the function call, return the same shape as bare JSON instead of failing silently.
        PROMPT;

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Analyze this listing title: \"{$title}\""],
            ],
            'functions' => [$this->getTitleAnalysisToolDefinition()],
            'function_call' => ['name' => 'analyze_listing_title'],
        ]);

        $this->logUsage('analyze_title', $response);
        $result = $this->extractToolResponse($response);
        if (!is_array($result)) {
            return $result;
        }

        // Pure PHP scoring — LLM is no longer trusted to grade. We extract
        // features from the input title against the listing context and the
        // suggestions go through the shared pipeline.
        $inputFeatures = $this->extractTitleFeatures($title, $context);
        $inputScoring  = $this->scoreFromFeatures($inputFeatures, $context);
        $result['score']           = $inputScoring['score'];
        $result['score_breakdown'] = $inputScoring['score_breakdown'];

        return $this->processSuggestions($result, $context);
    }

    protected function getTitleAnalysisToolDefinition(): array
    {
        return [
            'name' => 'analyze_listing_title',
            'description' => 'Analyze a real estate listing title — provide concise SEO feedback and propose 3 improved alternatives. Scoring is computed deterministically in PHP from the title strings; do NOT include any scores in your output.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'feedback' => [
                        'type' => 'string',
                        'description' => 'Brief, specific feedback on the input title (2-3 sentences max). Call out missing keywords / missing location / fluff, etc.',
                    ],
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Improved title candidate (~8-12 words; soft ~65-char cap). Use only facts from the listing context — never invent.',
                                ],
                            ],
                            'required' => ['title'],
                        ],
                        'description' => 'Exactly 3 improved title alternatives, each targeting a different query pattern.',
                    ],
                ],
                'required' => ['feedback', 'suggestions'],
            ],
        ];
    }

    /**
     * Unified title helper. One entry point for the frontend's "Improve Title"
     * button that handles both modes based on whether the agent has typed
     * anything yet:
     *   - title is empty (or <3 words) → generate 3 fresh SEO-strong titles
     *     from the listing context (suggestTitles flow)
     *   - title is present (≥3 words)  → score + feedback + 3 improvement
     *     suggestions anchored to the typed title (analyzeTitle flow)
     *
     * Always returns a `suggestions` array; `score` and `feedback` are only
     * present in analyze mode. `mode` is included so the frontend can render
     * the score card conditionally.
     */
    public function improveTitle(?string $title, array $context = []): ?array
    {
        $trimmed = is_string($title) ? trim($title) : '';
        $wordCount = $trimmed === ''
            ? 0
            : count(array_filter(preg_split('/\s+/', $trimmed)));

        if ($wordCount >= 3) {
            $result = $this->analyzeTitle($trimmed, $context);
            if ($result) {
                $result['mode'] = 'analyze';
            }
            return $result;
        }

        // Both flows now return `suggestions: [{ title, score }]` directly,
        // so we only need to tag the mode for the frontend.
        $result = $this->suggestTitles($context);
        if ($result) {
            $result['mode'] = 'generate';
        }
        return $result;
    }

    /**
     * Suggest titles from listing context only — used when the agent hasn't
     * typed a title yet and wants the AI to draft 3 candidates from the
     * structured data they've already filled in (category, type, project,
     * location, beds/baths, area, amenities, photo keywords).
     */
    public function suggestTitles(array $context = []): ?array
    {
        $contextBlock = $this->buildListingContextBlock($context);

        $prompt = <<<PROMPT
        You are an SEO writer for Philippine real estate. Write 3 listing titles with the best chance of ranking on Google for what PH buyers/renters actually type. PHP scores titles deterministically (0–100) — aim for 85+ on every suggestion. Do NOT output any scores or ratings, only title strings.

        MANDATORY — every title needs ALL FOUR or it scores low:
        1. Transaction intent — the EXACT phrase from the "Listing category" ("For Sale", "For Rent", or "Foreclosure"; ~25 pts). The intent phrase MUST be the FIRST words of every title (e.g. "For Sale: …") — never buried mid-title. Never omit; never substitute the property type ("Condominium:", "Townhouse:") for intent. PREFERRED INTENT OVERRIDE: if the context includes a "Preferred intent phrase" (an agent-chosen substitute — For Sale listings may use "Rush Sale"/"For Assume"/"Sacrifice Sale"; For Rent listings may use "For Lease"/"Rent to Own"; For Sale and Foreclosure listings may also use "Ready for Occupancy" — never on For Rent), then use THAT exact phrase as the leading intent of ALL 3 titles instead of the category phrase.
        2. Property keyword — use the MOST-SEARCHED term for the type: Condominium → "Condo" + the bedroom count (subtype "2 Bedrooms" → "2BR Condo"; subtype Studio/Penthouse/Loft → that word); House → the SUBTYPE (Townhouse, House and Lot, Apartment, Beach House), NOT the bare word "House"; Commercial → the SUBTYPE (Warehouse, Office, Building, Hotel); Land → SUBTYPE only, never "Land" (see LAND LISTINGS). Abbreviations ok.
        3. Most specific location — barangay AND city when both are in context, else city alone.
        4. Bedroom count ("NBR"/"N-Bedroom") when context provides bedrooms — DWELLINGS ONLY (see LAND LISTINGS).

        LAND LISTINGS (when Property type is "Land"): do NOT include any bedroom count, even if context shows a bedroom value — land has no bedrooms. The property keyword MUST be the SUBTYPE alone — NEVER append the word "Land" (that is the type, not a keyword). So write "Island" (NOT "Island Land"), "Memorial Lot" (write "Memorial" as "Memorial Lot"), and every other land subtype verbatim since it already contains "Lot" (Residential Lot, Commercial Lot, Agricultural Lot, Beach Lot, Industrial Lot). Lot/floor area ("N sqm") is the headline spec for land — always include it when context provides it.

        BONUSES (include as many as fit naturally): size "N sqm" (only if context has floor/lot area); a landmark from the "Nearby:" line, verbatim; project name verbatim; query-shape phrasing that reads like a real Google search ("for sale in Mandaue", "3BR condo near Mandani Bay", "house for rent in Talamban").

        HALLUCINATION GUARD: locations and landmarks are validated against context — invented ones are stripped and score lower. Only use landmarks from the "Nearby:" line and locations from the address/barangay/city/province fields.

        LENGTH & TONE: 9–13 words ideal (up to 18 if PH names force it, ~75 char soft cap). No ALL CAPS, "!", emojis, or filler ("nice", "beautiful", "must-see", "dream home", "amazing").

        THREE VARIATIONS — each hits all four mandatory points, targets a DIFFERENT angle, and includes at least one fact the other two omit (not word-shuffles; if two share >70% of words, rewrite). If project_name overlaps with a landmark, use it in only one variation and anchor the others differently (a photo keyword, amenity, different landmark, or size/floor detail):
        1. Keyword/feature-led: "For [Sale/Rent/Foreclosure]: NBR [Subtype] in [Barangay], [City] — [Size or Distinct Feature]" (e.g. "For Sale: 3BR Condo in Centro, Mandaue City — 65 sqm with Balcony") — pack quantified specs; most likely to rank for "[NBR] [type] for sale in [city]" queries.
        2. Landmark/lifestyle-led: "For [Sale/Rent]: NBR [Subtype] Near [Landmark], [City] — [Photo Keyword or Amenity]" (e.g. "For Sale: 1BR Condo Near Mandani Bay Boardwalk, Mandaue City — Sea View") — must include a photo keyword/amenity the others omit; if no landmark, anchor on a unique amenity.
        3. Project/buyer-benefit-led: "For [Sale/Rent]: [Project] [Subtype] in [Barangay], [City] — [Buyer Hook]" (e.g. "For Sale: Mandani Bay Studio in Centro, Mandaue City — Move-in Ready Investment") — target a persona (investor, family, professional, retiree) the others don't; if no project, use a size-led form.

        {$contextBlock}

        Return the function call. If the runtime cannot execute the function call, return the same shape as bare JSON instead of failing silently.
        PROMPT;

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => 'Suggest 3 SEO-strong listing titles using the context.'],
            ],
            'functions' => [$this->getTitleSuggestionToolDefinition()],
            'function_call' => ['name' => 'suggest_listing_titles'],
        ]);

        $this->logUsage('suggest_titles', $response);
        $result = $this->extractToolResponse($response);
        if (!is_array($result)) {
            return $result;
        }

        // Shared PHP scoring pipeline — pure PHP feature extraction +
        // axis aggregation against the listing context. Same path as
        // analyzeTitle's suggestion list.
        return $this->processSuggestions($result, $context);
    }

    protected function getTitleSuggestionToolDefinition(): array
    {
        return [
            'name' => 'suggest_listing_titles',
            'description' => 'Suggest 3 SEO-strong real estate listing titles. Scoring is computed deterministically in PHP from the title strings; do NOT include any scores in your output.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Listing title candidate (~8-12 words; soft ~65-char cap). Use only facts from the listing context — never invent.',
                                ],
                            ],
                            'required' => ['title'],
                        ],
                        'description' => 'Exactly 3 listing title candidates. Target three different query patterns (formula / landmark / project) when context allows; otherwise vary by hook.',
                    ],
                ],
                'required' => ['suggestions'],
            ],
        ];
    }

    protected function getImageClassificationToolDefinition(): array
    {
        return [
            'name' => 'classify_image_with_description',
            'description' => 'Classify real estate listing images and optionally describe them',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'image_id' => [
                                    'type' => 'integer',
                                    'description' => 'Id of the image in the input array of objects'
                                ],
                                'classification' => [
                                    'type' => 'string',
                                    'enum' => ['Bad', 'Good', 'Excellent'],
                                    'description' => 'Quality classification of the image'
                                ],
                                'description' => [
                                    'type' => 'string',
                                    'description' => 'Short keyword-based description (ONLY for Good and Excellent images). Return empty string if Bad.'
                                ]
                            ],
                            'required' => ['image_index', 'classification', 'description']
                        ]
                    ]
                ],
                'required' => ['results']
            ]
        ];
    }

    protected function getToolDefinition(): array
    {
        return  [
            'name' => 'parse_listing_query',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'categories' => ['type'=>'array','items'=>['type'=>'string']],
                    'types' => ['type'=>'array','items'=>['type'=>'number']],
                    'subtypes' => ['type'=>'array','items'=>['type'=>'number']],
                    'furnishings' => ['type'=>'array','items'=>['type'=>'number']],
                    'amenities' => ['type'=>'array','items'=>['type'=>'string']],
                    'beds' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => [
                                'type' => 'number',
                                'description' => 'Return 0 if not mentioned.'
                            ],
                            'condition' => [
                                'type' => 'string',
                                'enum' => ['equal', 'plus'],
                                'description' => 'equal = exact, plus = greater or equal'
                            ]
                        ],
                        'required' => ['value', 'condition']
                    ],
                    'baths' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => [
                                'type' => 'number',
                                'description' => 'Return 0 if not mentioned.'
                            ],
                            'condition' => [
                                'type' => 'string',
                                'enum' => ['equal', 'plus'],
                                'description' => 'equal = exact, plus = greater or equal'
                            ]
                        ],
                        'required' => ['value', 'condition']
                    ],
                    'priceMin' => ['type'=>['number','null']],
                    'priceMax' => ['type'=>['number','null']],
                    'sqmMin' => ['type'=>['number','null']],
                    'sqmMax' => ['type'=>['number','null']],
                    'search' => [
                        'type'=>'string',
                        'description'=>'The actual value of the prompt.'
                    ],
                    'key_word' => [
                        'type' => 'string',
                        'description' => <<<DESC
                    Extract a short, keyword-based phrase that describes the user's contextual search intent.

                    Guidelines:
                    - Use ONLY words or phrases that appear in the query.
                    - Do NOT infer or add terms that are not explicitly mentioned.
                    - EXCLUDE:
                    - Property types (e.g. house, condo, apartment, lot, office, townhouse)
                    - ANY location names (e.g. cities, provinces, areas like BGC, Cebu, Makati)
                    - Keep it SHORT (1–5 words only).
                    - Do NOT include full sentences.
                    - Do NOT include filler words like "looking for", "find", "show me", etc.

                    Focus ONLY on descriptive/contextual elements such as:
                    - Environment or surroundings (e.g. near beach, beachfront, mountain view, city view)
                    - Features or attributes (e.g. with parking, furnished, pet friendly, with pool, gated)
                    - Lifestyle or intent (e.g. affordable, luxury, quiet, modern)
                    - Numerical features ONLY if not tied to property type (e.g. 2 bedroom → keep "2 bedroom")

                    - Preserve natural keyword combinations (e.g. "near beach", "with parking", "pet friendly furnished").

                    IMPORTANT:
                    - If ANY meaningful descriptive/context keyword exists, you MUST return a value.
                    - Only return "" if the query has no usable descriptive/context words.

                    Examples:
                    - "Looking for a house near the beach in Cebu" → "near beach"
                    - "2 bedroom apartment with parking Makati" → "2 bedroom parking"
                    - "Affordable condo for rent" → "affordable"
                    - "Luxury villa with pool in BGC" → "luxury with pool"
                    DESC
                    ],
                    'address' => ['type'=>'string'],
                    'barangay' => ['type'=>'string'],
                    'city' => ['type'=>'string'],
                    'province' => ['type'=>'string'],
                ],
                'required' => [
                    'categories','types','subtypes','furnishings','amenities',
                    'beds','baths','priceMin','priceMax','sqmMin','sqmMax',
                    'search','address','key_word','barangay','city','province'
                ]
            ]
        ];
    }

    public function generateDescription(string $title, array $context = []): ?array
    {
        $contextBlock = $this->buildListingContextBlock($context);

        $prompt = <<<PROMPT
        You are an SEO copywriter for Philippine real estate. Write a listing description that ranks in Google AND converts readers. Natural prose only — no lists, headers, or fluff.

        SNIPPET RULE (most important — Google shows the first sentence): the FIRST SENTENCE must fit ~155 characters and pack ALL of these (vary the order every time, never a fixed template): transaction intent ("For Sale"/"For Rent"/"Foreclosure"); property type/subtype ("3-bedroom condo", "house and lot"); location (barangay if available, else city); and ONE distinctive fact about THIS listing (a photo-keyword feature, amenity, view, or nearby landmark). Never lead with "For Sale:" or "Discover this beautiful…".

        PROJECT LISTINGS (when a "Project/Development name" is in context): this listing is part of a project — the FIRST SENTENCE must LEAD with the project name (verbatim), then weave in the intent, type/subtype, and location. Example: "Mandani Bay Suites offers a for-sale 1-bedroom condo in Centro, Mandaue City with sea views." Mention the project name only this once; do not repeat it.

        BODY (continue naturally after the snippet): property specifics with the real numbers (sqm, beds, baths, parking, furnishing); location & nearby — name at least one landmark by its exact name when context has a "Nearby" entry ("a 5-minute drive from Ayala Center Cebu"); lifestyle fit & target buyer (families, young professionals, investors) grounded in 1–2 photo-keyword details; close with a factual value summary or soft CTA — never "Don't miss out", "Inquire now", or "Hurry".

        LAND LISTINGS (when Property type is "Land"): land has NO bedrooms/bathrooms/parking/floor area — NEVER mention them, even if a stray value appears in context. Refer to the property by its SUBTYPE alone — NEVER append the word "Land" (write "island", "memorial lot", "residential lot", etc., not "island land"). The headline specs are lot area and price; the buyer focus is the use of the parcel (residential build, commercial, agricultural, memorial, etc.), not "rooms".

        SEO REQUIREMENTS:
        - LENGTH IS ADAPTIVE — match it to how much real data exists. Rich listing (many specs/amenities/landmarks): 180–250 words. Sparse listing (e.g. bare land with only area + price): 80–140 words. NEVER pad to hit a word count — a tight 90-word description beats a 220-word one that repeats itself.
        - Use the exact transaction-intent phrase at least once (lowercase ok in body).
        - PREFERRED INTENT: if the context includes a "Preferred intent phrase" (e.g. "Rush Sale", "For Lease", "Ready for Occupancy"), weave that phrase in naturally — AND STILL state the real category intent phrase ("for sale"/"for rent"/"foreclosure") at least once, so the true transaction type is never lost. Both must appear.
        - State the FULL location ("Barangay, City, Province") at most ONCE. After that, refer to it briefly — just the barangay, just the city, or "the area" / "nearby". NEVER repeat the full "Barangay, City, Province" string more than once; that is keyword stuffing and hurts SEO.
        - Property keyword (mention 1–2 times) — use the most-searched term: Condominium → "condo" + bedroom count ("1-bedroom condo"); House → the subtype ("townhouse", "house and lot", "apartment"), not bare "house"; Commercial → the subtype ("warehouse", "office", "building"); Land → subtype only, never "land" (see LAND LISTINGS).
        - When nearby_facilities is provided, weave 1–2 landmarks in by their REAL names (no "near a mall" — use "near SM Seaside" if it's in context).
        - Use the project name verbatim exactly once, leading the first sentence (see PROJECT LISTINGS).
        - Include ONE long-tail phrase buyers actually type ("ideal for families looking for X in [city]", "[type] near [landmark]") — only if it fits naturally.
        - State each real number (area, price, bed/bath/parking) ONCE. Do not restate the same figure in multiple sentences.

        STRICT — NO REDUNDANCY:
        - Never restate the same fact (location, price, area, subtype) more than the limits above. If you catch yourself repeating, the description is too long — CUT it, don't reword the repeat.
        - No bullets, headers, emojis, or ALL CAPS.
        - No filler ("dream home", "must see", "once in a lifetime", "rare opportunity", "amazing", "stunning" — one use max if it describes a real feature).
        - No invented facts — if a number/feature/landmark/project isn't in context, don't add it. Thin data → short description, never padding.
        - Vary sentence 1 every time; keep sentences 12–18 words on average, no back-to-back long sentences.

        {$contextBlock}

        Return ONLY the function call.
        PROMPT;

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Listing title: \"{$title}\""],
            ],
            'functions' => [$this->getDescriptionToolDefinition()],
            'function_call' => ['name' => 'generate_description'],
        ]);

        $this->logUsage('generate_description', $response);
        return $this->extractToolResponse($response);
    }

    /**
     * Build the shared "known listing details" context block used by both
     * analyzeTitle and generateDescription. Keeps prompt construction
     * consistent — when more fields are added, both flows pick them up.
     */
    protected function buildListingContextBlock(array $context): string
    {
        $lines = [];

        $simple = [
            'category'         => 'Listing category',
            'property_type'    => 'Property type',
            'property_subtype' => 'Property subtype',
            'project_name'     => 'Project/Development name',
            'project_location' => 'Project location',
            'bedrooms'         => 'Bedrooms',
            'bathrooms'        => 'Bathrooms',
            'parking'          => 'Parking/Garage',
            'furnishing'       => 'Furnishing',
        ];
        foreach ($simple as $key => $label) {
            if (!empty($context[$key])) {
                $lines[] = "{$label}: {$context[$key]}";
            }
        }

        // Agent-chosen substitute intent (e.g. "Rush Sale" instead of "For
        // Sale") — the title prompts lead with it and the description prompt
        // must still state the real category phrase alongside it.
        if (!empty($context['preferred_intent']) && is_string($context['preferred_intent'])) {
            $lines[] = "Preferred intent phrase: {$context['preferred_intent']}";
        }

        // Extra agent-chosen keywords (e.g. "Ready for Occupancy") that ride
        // along with the intent — include each naturally in every output.
        if (!empty($context['preferred_keywords']) && is_array($context['preferred_keywords'])) {
            $keywords = implode(", ", array_filter($context['preferred_keywords'], 'is_string'));
            if ($keywords !== '') {
                $lines[] = "Preferred keywords (include each naturally in every title/description): {$keywords}";
            }
        }

        if (!empty($context['price'])) {
            $price = is_numeric($context['price']) ? number_format((float) $context['price']) : $context['price'];
            $lines[] = "Price: PHP {$price}";
        }
        if (!empty($context['floor_area'])) {
            $lines[] = "Floor area: {$context['floor_area']} sqm";
        }
        if (!empty($context['lot_area'])) {
            $lines[] = "Lot area: {$context['lot_area']} sqm";
        }

        $loc = array_filter([
            $context['barangay']  ?? null,
            $context['city']      ?? null,
            $context['province']  ?? null,
        ]);
        if (!empty($loc)) {
            $lines[] = "Location: " . implode(", ", $loc);
        } elseif (!empty($context['address'])) {
            $lines[] = "Address: {$context['address']}";
        }

        if (!empty($context['amenities']) && is_array($context['amenities'])) {
            $names = array_slice(array_filter($context['amenities']), 0, 20);
            if (!empty($names)) {
                $lines[] = "Amenities: " . implode(", ", $names);
            }
        }

        if (!empty($context['nearby_facilities']) && is_array($context['nearby_facilities'])) {
            $highlights = [];
            foreach ($context['nearby_facilities'] as $kind => $items) {
                if (!is_array($items) || empty($items)) continue;
                $names = collect($items)->pluck('name')->filter()->take(2)->all();
                if (!empty($names)) {
                    $highlights[] = ucfirst(str_replace('_', ' ', $kind)) . ": " . implode(", ", $names);
                }
            }
            if (!empty($highlights)) {
                $lines[] = "Nearby: " . implode("; ", $highlights);
            }
        }

        if (!empty($context['photo_keywords'])) {
            $kw = is_array($context['photo_keywords'])
                ? implode(", ", array_slice($context['photo_keywords'], 0, 15))
                : (string) $context['photo_keywords'];
            $lines[] = "Photo keywords (from classified images): {$kw}";
        }

        if (!empty($context['description'])) {
            $desc = mb_substr($context['description'], 0, 300);
            $lines[] = "Existing description excerpt: {$desc}";
        }

        return empty($lines)
            ? "No additional listing details provided."
            : "KNOWN LISTING DETAILS (use these verbatim — never invent or substitute):\n" . implode("\n", $lines);
    }

    protected function getDescriptionToolDefinition(): array
    {
        return [
            'name' => 'generate_description',
            'description' => 'Generate a real-estate listing description from a title plus context (attributes, location, amenities, photo keywords).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'description' => [
                        'type' => 'string',
                        'description' => 'Natural-sounding real-estate description, 100-170 words, flowing prose, no bullet lists, no hype phrases.',
                    ],
                ],
                'required' => ['description'],
            ],
        ];
    }

    protected function extractToolResponse($response): ?array
    {
        $message = $response->choices[0]->message ?? null;

        // Prefer the function-call arguments. But the model sometimes ignores
        // the function call and returns the same JSON as plain text content
        // instead (the prompt explicitly allows this as a fallback). Reading
        // only functionCall used to drop that valid answer → silent null →
        // "Failed to generate title suggestions." So fall back to content.
        $raw = $message->functionCall->arguments ?? $message->content ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Strip ```json … ``` fences the model wraps bare-JSON fallbacks in.
        $raw = trim(preg_replace('/^\s*```(?:json)?|```\s*$/m', '', trim($raw)));

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Was invisible before — log so truncated/malformed output is
            // diagnosable instead of just surfacing as a generic failure.
            Log::warning('openai.extract_failed', ['raw' => mb_substr($raw, 0, 500)]);
            return null;
        }

        return $decoded;
    }

    /**
     * Log token usage from an OpenAI chat completion. Captures the model,
     * the called function, and prompt/completion/total tokens so we can
     * track cost per AI feature over time and catch sudden token-burn
     * regressions in a prompt change. Never throws — wrapped in try/catch
     * so a missing field never breaks the AI flow itself.
     */
    protected function logUsage(string $flow, $response): void
    {
        try {
            $usage = $response->usage ?? null;
            Log::info("openai.{$flow}", [
                'model'             => $response->model ?? null,
                'prompt_tokens'     => $usage?->promptTokens,
                'completion_tokens' => $usage?->completionTokens,
                'total_tokens'      => $usage?->totalTokens,
            ]);
        } catch (\Throwable $e) {
            // never let logging break the request
        }
    }

    protected function normalize(array $data): array
    {
        $subtypeMap = collect($this->taxonomy['subtypes'])
            ->mapWithKeys(fn($s) => [$s['id'] => $s['type']['id']]);

        foreach ($data['subtypes'] as $subId) {
            if (isset($subtypeMap[$subId])) {
                $data['types'][] = $subtypeMap[$subId];
            }
        }

        $data['types'] = array_values(array_unique($data['types']));
        $data['subtypes'] = array_values(array_unique($data['subtypes']));

        return $data;
    }
}