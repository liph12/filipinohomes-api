<?php

namespace App\Services\OpenAI;

use OpenAI;

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

        return $this->extractToolResponse($response);
    }

    public function analyzeTitle(string $title, array $context = []): ?array
    {
        // Build rich context from all available listing data
        $contextLines = [];

        if (!empty($context['category'])) {
            $contextLines[] = "Listing category: {$context['category']}";
        }
        if (!empty($context['property_type'])) {
            $contextLines[] = "Property type: {$context['property_type']}";
        }
        if (!empty($context['property_subtype'])) {
            $contextLines[] = "Property subtype: {$context['property_subtype']}";
        }
        if (!empty($context['project_name'])) {
            $contextLines[] = "Project/Development name: {$context['project_name']}";
        }
        if (!empty($context['project_location'])) {
            $contextLines[] = "Project location: {$context['project_location']}";
        }
        if (!empty($context['bedrooms'])) {
            $contextLines[] = "Bedrooms: {$context['bedrooms']}";
        }
        if (!empty($context['bathrooms'])) {
            $contextLines[] = "Bathrooms: {$context['bathrooms']}";
        }
        if (!empty($context['floor_area'])) {
            $contextLines[] = "Floor area: {$context['floor_area']} sqm";
        }
        if (!empty($context['lot_area'])) {
            $contextLines[] = "Lot area: {$context['lot_area']} sqm";
        }
        if (!empty($context['description'])) {
            // Truncate long descriptions to keep prompt manageable
            $desc = mb_substr($context['description'], 0, 300);
            $contextLines[] = "Description excerpt: {$desc}";
        }

        $contextBlock = !empty($contextLines)
            ? "KNOWN LISTING DETAILS (use these for accurate suggestions):\n" . implode("\n", $contextLines)
            : "No additional listing details provided.";

        $prompt = <<<PROMPT
        You are an SEO expert for Philippine real estate listings.

        CRITICAL RULES:
        1. NEVER assume or invent a location. If no location is provided in the title or context, do NOT add any city/area name to suggestions. Instead, note in the feedback that adding a location would improve SEO.
        2. If a project name or location IS provided (e.g. "Mavesa" in Davao, "Solinea" in Cebu), your suggestions MUST use that exact project name and its correct location. NEVER substitute with a different city or area.
        3. If the title mentions a project or place name you recognize, use the correct associated location. If you don't recognize it, check the context fields below. If still unknown, keep the name as-is without guessing a location.

        ANALYSIS TASK:
        1. Score the title from 0-100 based on SEO effectiveness
        2. Give brief, specific feedback (2-3 sentences) on what to fix
        3. Suggest exactly 3 improved alternatives

        SCORING CRITERIA:
        - Follows keyword-rich formula: [Status] + [Size/Type] + [Location] + [Key Benefit]
          Example: "For Sale: 3BR House & Lot in Talamban Cebu – Near USC, Ready for Occupancy"
        - Highlights 1-2 strong selling points (not generic fluff)
          Good hooks: "Walking distance to Ayala", "Near IT Park", "Preselling, low monthly DP", "with parking"
        - 10-15 words, no ALL CAPS, readable on mobile
        - Uses clear buyer search keywords: "3BR", "parking", "near mall", "RFO", "fully furnished", "with views"
        - Penalize heavily:
          * Generic/vague titles: "Nice House in Cebu", "Beautiful Property"
          * Spammy language: "SUPER CHEAP!!! HURRY!!!", all caps, excessive punctuation
          * Too short (under 5 words) or too long (over 20 words)
          * Missing category status (For Sale/For Rent) when the listing category is known
          * Wrong location — mentioning a city/area that contradicts the actual project location

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

    protected function extractToolResponse($response): ?array
    {
        $toolCall = $response->choices[0]->message->functionCall ?? null;

        if (!$toolCall) return null;

        return json_decode($toolCall->arguments, true);
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