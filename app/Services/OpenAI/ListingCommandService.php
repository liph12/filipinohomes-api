<?php

namespace App\Services\OpenAI;

use OpenAI;
use Illuminate\Support\Facades\Log;

class ListingCommandService
{
    protected $client;
    protected array $taxonomy;

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

    public function analyzeTitle(string $title, array $context = []): ?array
    {
        $contextBlock = $this->buildListingContextBlock($context);

        $prompt = <<<PROMPT
        You are an SEO expert for Philippine real estate listings. Your job is to score whether this title will rank in Google's top results for relevant buyer queries, then suggest stronger alternatives.

        CRITICAL RULES (these are facts, never invent):
        1. NEVER assume or invent a location. If no location is given in the title or context, your feedback should say "adding a city/barangay would improve SEO" but your suggestions must NOT add a city you guessed.
        2. If a project name or location IS provided, your suggestions MUST use them verbatim with the correct city — never substitute.
        3. Recognize Philippine project names only when paired with context confirming the city. Otherwise pass through what's in the title.

        ANALYSIS TASK:
        1. Score 0–100 based on Google-ranking SEO effectiveness for the actual queries PH buyers/renters type.
        2. Feedback: 2–3 specific, actionable sentences. Reference what's missing and what to add.
        3. Suggest exactly 3 improved titles. Each must follow the rules below and target a slightly different query pattern.

        WHAT GOOGLE ACTUALLY RANKS (high signal, in order):
        - **Front-loaded primary keywords**: the first 3 words should contain the transaction intent ("For Sale" / "For Rent" / "Foreclosure") OR a bedroom count ("3BR", "2 Bedroom") OR the property type/subtype ("Condo", "House and Lot"). Buyers scan the first words; Google weights them most.
        - **Specific location**: barangay > city > province. A title with "Talamban, Cebu City" outranks "in Cebu" for the same query.
        - **Recognized nearby landmark when available**: phrases like "near Ayala Center", "walking distance to SM Seaside", "beside IT Park" massively boost long-tail rankings AND CTR. If the context has nearby malls / attractions / parks, AT LEAST ONE of the 3 suggestions should reference one of them by exact name.
        - **Quantified specifics**: "3BR", "50 sqm", "PHP 5M", "RFO 2025". Numbers and acronyms match how buyers actually search.
        - **Length sweet spot**: 8–12 words / ~55–65 characters. Google truncates titles past ~60 chars in mobile SERPs.

        PENALIZE HEAVILY (cap the score below 60 if any apply):
        - Generic openers: "Nice House", "Beautiful Property", "Dream Home", "Must See"
        - Hype/spam: ALL CAPS, exclamation marks, "RUSH!!!", "AMAZING DEAL"
        - Missing transaction intent when the context has a category
        - Missing location when the context has city/barangay
        - Title under 5 words OR over 16 words
        - Two or more filler adjectives in a row ("Beautiful spacious modern...")

        BONUS POINTS (raise score when present):
        - Mentions a nearby landmark from the context (mall, park, attraction) — biggest single ranking signal we have
        - Uses the project name verbatim if provided
        - Includes a unique-but-true detail from photo keywords (e.g., "city view", "swimming pool")

        SUGGESTION VARIATION:
        - Suggestion 1: keyword-led formula → "[Category]: [Size/Type] in [Barangay/City] — [Distinct Feature]"
        - Suggestion 2: long-tail/landmark led → "[Size] [Type] for [Sale/Rent] Near [Landmark from context], [City]"
        - Suggestion 3: project- or benefit-led → "[Project Name] [Subtype] for [Sale/Rent] in [City] — [Lifestyle Hook]"

        {$contextBlock}

        Return function call ONLY with structured JSON.
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
        return $this->extractToolResponse($response);
    }

    protected function getTitleAnalysisToolDefinition(): array
    {
        return [
            'name' => 'analyze_listing_title',
            'description' => 'Analyze a real estate listing title for SEO quality and suggest improvements',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'score' => [
                        'type' => 'integer',
                        'description' => 'SEO score from 0 to 100'
                    ],
                    'feedback' => [
                        'type' => 'string',
                        'description' => 'Brief feedback on the title quality (2-3 sentences max)'
                    ],
                    'suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Exactly 3 improved title suggestions following the SEO formula'
                    ],
                ],
                'required' => ['score', 'feedback', 'suggestions']
            ]
        ];
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
        You are an SEO expert for Philippine real estate. Your job: write 3 listing titles that have the best chance of ranking #1 on Google for what PH buyers/renters actually type. Optimize for search visibility AND click-through rate.

        OUTPUT: 3 title candidates targeting slightly different query patterns.

        STRICT RULES:
        - 8–12 words / 55–65 characters each. Google truncates anywhere past ~60 chars in mobile search results.
        - First 3 words must contain the strongest search keyword: a bedroom count ("3BR"), the transaction intent ("For Sale" / "For Rent" / "Foreclosure"), or the property subtype ("Condo", "House and Lot"). Buyers scan left to right; Google weights left-side tokens highest.
        - Use ONLY the facts in the context. NEVER invent locations, prices, sizes, project names, or features.
        - If a project name is provided, use it verbatim in every title.
        - Include the most specific location available: barangay > city > province.
        - No ALL CAPS. No exclamation marks. No emojis. No filler adjectives ("nice", "beautiful", "amazing", "must-see", "dream home").

        TITLE VARIATIONS (one of each):
        1. **Keyword/formula-led** → "[Transaction Intent]: [N]BR [Subtype] in [Barangay/City] — [Quantified Feature]"
           Example: "For Sale: 3BR Condo in Cebu IT Park — 65 sqm, Fully Furnished"
        2. **Landmark/long-tail led** → "[Size] [Type] for [Sale/Rent] Near [Real Landmark from Context], [City]"
           Example: "55 sqm Condo for Sale Near Ayala Center Cebu — RFO 2026"
           ➜ When the context has a "Nearby" entry (mall, park, attraction), USE the exact landmark name. This is the single highest-impact ranking signal we can add.
        3. **Project- or benefit-led** → "[Project Name] [Subtype] in [City] — [Specific Lifestyle Hook]"
           Example: "Solinea Studio in Cebu Business Park — High Floor with City View"

        WHAT TO BOOST (use when context provides):
        - Exact nearby landmark names (malls, parks, attractions) → highest single SEO signal
        - Quantified specs (3BR, 50 sqm, 2-car garage, PHP 5M)
        - Photo keywords describing visible features (city view, pool, balcony)
        - Project name verbatim

        ANTI-EXAMPLES (Google deprioritizes these, never produce them):
        - "Nice House in Cebu" → vague, no specifics
        - "Beautiful Property For Sale" → no location, no type, fluff
        - "AMAZING UNIT — DON'T MISS!!!" → spam signals
        - "Must-See Home for the Whole Family" → no SEO keywords

        {$contextBlock}

        Return ONLY the function call.
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
        return $this->extractToolResponse($response);
    }

    protected function getTitleSuggestionToolDefinition(): array
    {
        return [
            'name' => 'suggest_listing_titles',
            'description' => 'Suggest 3 SEO-strong real estate listing titles from structured listing context.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'titles' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Exactly 3 listing title candidates, each 10-15 words, following the SEO formula.',
                    ],
                ],
                'required' => ['titles'],
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
        You are an SEO copywriter for Philippine real estate. Your job: write a listing description that ranks in Google's top results AND converts readers. Natural prose only — no lists, no headers, no fluff.

        SNIPPET RULE (most important — Google shows this in search):
        The FIRST SENTENCE must fit ~155 characters and pack ALL these facts (vary the order each time, never use a fixed template):
        - Transaction intent: "For Sale", "For Rent", or "Foreclosure"
        - Property type/subtype (e.g., "3-bedroom condo", "house and lot", "townhouse")
        - Location: barangay if available, else city
        - ONE distinctive fact about THIS listing — a photo-keyword feature, a specific amenity, a view, or a nearby landmark. NEVER lead with a template like "For Sale:" or "Discover this beautiful…"

        BODY STRUCTURE (continue naturally after the snippet sentence):
        - Sentences 2–3: Property specifics. Use the real numbers — square meters, bedrooms, bathrooms, parking, furnishing. Spell out features the buyer would search for.
        - Sentences 4–5: Location & nearby. If the context has a "Nearby" entry (malls, parks, attractions, schools, hospitals), name AT LEAST ONE landmark by its exact name. "A 5-minute drive from Ayala Center Cebu" is gold for SEO.
        - Sentences 6–7: Lifestyle fit & target buyer. Who is this property right for — families, young professionals, investors? Mention 1–2 photo-keyword details to ground the description in what's actually visible.
        - Closing sentence: A specific, factual value summary OR soft CTA ("Schedule a viewing to see the unit's natural light firsthand"). NEVER use cliches like "Don't miss out", "Inquire now", or "Hurry".

        SEO REQUIREMENTS:
        - **Length: 180–250 words.** Google ranks deeper, content-rich pages above thin ones for competitive PH real-estate queries.
        - Include the exact transaction-intent phrase ("for sale" / "for rent" / "foreclosure") at least once, lowercase is fine in body.
        - Mention the barangay AND city naturally — 2+ times across the body, never consecutively.
        - Mention the property subtype 1–2 times (e.g., "this 1-bedroom condo", "the house and lot").
        - When nearby_facilities is provided, weave 1–2 landmarks into the prose by their REAL names (no "near a mall" — use "near SM Seaside" if SM Seaside is in the context).
        - If a project name is provided, use it verbatim once in the first or second sentence.
        - Naturally include 1–2 long-tail phrases buyers actually type: "ideal for families looking for X in [city]", "[type] near [landmark]", "[city] [feature] within walking distance".
        - Use ALL real numbers from context — sqm, bed/bath/parking counts, price, amenities, photo keywords. Numbers signal authority and answer specific search queries.

        STRICT NO-LIST (penalize anything that triggers Google's spam classifiers):
        - No bullet points, no section headers, no emojis, no ALL CAPS.
        - No filler phrases: "dream home", "must see", "once in a lifetime", "perfect for everyone", "rare opportunity", "amazing", "stunning" (one use max if it describes a real photo-keyword feature).
        - No invented facts. If a number/feature/landmark/project isn't in the context, do NOT add it.
        - No repeated openings across listings — vary the structure of sentence 1 every time.
        - Keep average sentence length 12–18 words. No back-to-back long sentences.

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
        $toolCall = $response->choices[0]->message->functionCall ?? null;

        if (!$toolCall) return null;

        return json_decode($toolCall->arguments, true);
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