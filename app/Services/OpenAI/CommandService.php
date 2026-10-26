<?php

namespace App\Services\OpenAI;

use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

class CommandService extends CacheService
{
    private $client;
    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    public function streamMessage(string $message)
    {
        return new StreamedResponse(function () use ($message) {
            $words = preg_split('/(\s+)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
    
            foreach ($words as $word) {
                if (trim($word) === '') {
                    echo "data: " . json_encode(['text' => $word]) . "\n\n";
                } else {
                    echo "data: " . json_encode(['text' => $word . ' ']) . "\n\n";
                }
                ob_flush();
                flush();
                usleep(30000);
            }
    
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function replyNormal(array $thread, $req)
    {    
        return new StreamedResponse(function () use ($thread, $req) {

            $fullText = '';
            
            $stream = $this->client->chat()->createStreamed([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge(
                    [
                        [
                            'role' => 'system',
                            'content' => <<<PROMPT
                            You are a friendly, professional real estate assistant for Filipino Homes (Philippines only).

                            BEHAVIOR:
                            - Focus only on the MOST RECENT user message; use previous context only if needed.
                            - Stay strictly within Philippine real estate topics related to Filipino Homes listings.
                            - Keep responses concise, helpful, and human-like.
                            - Adapt immediately if the user changes location or preference.
                            - Ask a short follow-up if the request is vague.
                            - Guide the user toward relevant property listings.
                            - Do not provide information, advice, or services outside Filipino Homes real estate.
                            - If a mention name you can keep track that this is a real estate agent. Ask also for the details.

                            STRICT RULES:
                            - If the user asks about anything outside real estate (e.g., resumes, cooking, travel).
                            - Do not attempt to answer unrelated questions under any circumstance.

                            GOAL:
                            - Make the conversation feel like a real property agent helping the user find the right property.
                            - Only handle Filipino Homes real estate inquiries.
                            PROMPT
                        ]
                    ],
                    $thread
                ),
            ]);
    
            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';
                if ($text) {

                    $fullText .= $text;
                    
                    echo "data: " . json_encode(['text' => $text]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }
            $uuid = Str::uuid();
            $this->appendMessages($req, [
                [
                    'id' => $uuid,
                    'role' => 'assistant',
                    'content' => $fullText
                ]
            ]);
    
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function replySearchingAgentStream(array $thread, $req)
    {
        return new StreamedResponse(function () use ($thread, $req) {

            $fullText = '';

            $messages = array_merge(
                [
                    [
                        'role' => 'system',
                        'content' => <<<PROMPT
                        You are a friendly and professional assistant for Filipino Homes (a real estate platform in the Philippines).

                        TASK:
                        Respond naturally to the user's latest message.

                        RULES:
                        - Focus on the MOST RECENT user intent in the conversation.
                        - Inform the user that you are currently searching for agents that match their request.
                        - Ask them politely to wait while you find the best agent(s).
                        - Keep the response short, natural, and conversational.
                        - Stay strictly within real estate context.

                        DO NOT:
                        - Mention unrelated topics.
                        - Sound robotic or overly formal.
                        PROMPT
                    ],
                ],
                $thread
            );

            $stream = $this->client->chat()->createStreamed([
                'model' => 'gpt-5.4-mini',
                'messages' => $messages,
            ]);

            // Initial message indicating agent search mode
            echo "data: " . json_encode([
                'type' => 'mode',
                'mode' => 'agent'
            ]) . "\n\n";
            ob_flush();
            flush();

            // Stream the assistant's response chunk by chunk
            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';

                if (!empty($text)) {

                    $fullText .= $text;

                    echo "data: " . json_encode([
                        'type' => 'text',
                        'text' => $text
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }

            $uuid = Str::uuid();
            $this->appendMessages($req, [
                [
                    'id' => $uuid,
                    'role' => 'assistant',
                    'content' => $fullText
                ]
            ]);

            // Signal stream completion
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function replySearchingStream(array $thread, $req)
    {
        return new StreamedResponse(function () use ($thread, $req) {

            $fullText = '';
    
            $messages = array_merge(
                [
                    [
                        'role' => 'system',
                        'content' => <<<PROMPT
                        You are a friendly and professional assistant for Filipino Homes (a real estate platform in the Philippines).
                        
                        TASK:
                        Respond naturally to the user's latest message.
                                    
                        RULES:
                        - Focus on the MOST RECENT user intent in the conversation.
                        - Inform the user that you are currently searching for property listings that match their request.
                        - Ask them politely to wait while you gather the best options.
                        - Keep the response short, natural, and conversational.
                        - Stay strictly within real estate context.
                        
                        DO NOT:
                        - Mention unrelated topics.
                        - Sound robotic or overly formal.
                        PROMPT
                    ],
                ],
                $thread // ✅ full conversation thread
            );
    
            $stream = $this->client->chat()->createStreamed([
                'model' => 'gpt-5.4-mini',
                'messages' => $messages,
            ]);

            echo "data: " . json_encode([
                'type' => 'mode',
                'mode' => 'listing'
            ]) . "\n\n";
            ob_flush();
            flush();
    
            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';
    
                if (!empty($text)) {
                    $fullText .= $text;

                    echo "data: " . json_encode([
                        'type' => 'text',
                        'text' => $text
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }

            $uuid = Str::uuid();
            $this->appendMessages($req, [
                [
                    'id' => $uuid,
                    'role' => 'assistant',
                    'content' => $fullText
                ]
            ]);
    
            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function suggestedListings(array $thread, array $listings, $req)
    {
        $systemMessage = [
            'role' => 'system',
            'content' => <<<SYSTEM
            You are a helpful, friendly assistant for Filipino Homes. 
            From the provided property listings, pick the single best listing as 'suggested' and 2-5 alternatives as 'others'. 
            Return ONLY JSON with IDs and a concise message. 
            If no listings match, indicate it in the message and leave 'suggested' and 'others' empty.
            Include the date if necessary or if the user specify the date.

            RULES:
            - Always focus ONLY on the MOST RECENT user intent.
            - Ignore previous messages if they conflict with the latest message.
            SYSTEM
        ];
    
        // Merge system + conversation thread
        $messages = array_merge([$systemMessage], $thread);
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => $messages,
            'functions' => [
                [
                    'name' => 'pick_listings',
                    'description' => 'Pick the best property listing and other alternatives',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => [
                                'type' => 'string',
                                'description' => <<<DESC
                                A concise but informative message describing the selected listing. 
                                Include key property details: property type, location, price, notable features, and assigned agent’s name, email, and mobile number. 
                                Keep tone natural, helpful, and conversational. 
                                Use under 3-4 sentences for normal messages. 
                                
                                Formatting rules:
                                - If helpful, structure the message with short headers or bullet points.
                                - Bold key details using Markdown-style double asterisks (**).
                                - Example:
                                  **Property Details**
                                  - **Location:** Lahug, Cebu City
                                  - **Price:** ₱20,000/month
                                  - **Agent:** Juan Dela Cruz, juan@example.com, 09171234567
                                - If no listings are found, clearly state that and suggest refining the search.
                                DESC
                            ],
                            'follow_up' => [
                                'type' => 'string',
                                'description' => 'A context-aware follow-up message. 
                                For example, suggesting viewing, contacting the agent, or showing alternatives. 
                                Should feel natural and specific to the actual listing. Make it shorter and very precise.'
                            ],
                            'suggested' => [
                                'type' => ['integer', 'null'],
                                'description' => 'ID of the best matching listing, or null if none found'
                            ],
                            'others' => [
                                'type' => ['array', 'null'],
                                'items' => ['type' => 'integer'],
                                'description' => 'IDs of alternative listings, or null if none found'
                            ]
                        ],
                        'required' => ['message', 'suggested', 'others', 'follow_up']
                    ]
                ]
            ],
            'function_call' => ['name' => 'pick_listings'],
            // pass listings as context in user message
            'messages' => array_merge(
                [$systemMessage],
                $thread,
                [
                    [
                        'role' => 'user',
                        'content' => "Here are the available listings:\n" . json_encode($listings, JSON_PRETTY_PRINT)
                    ]
                ]
            ),
        ]);
    
        $fn = $response->choices[0]->message->functionCall ?? null;
    
        if ($fn && isset($fn->arguments)) {
            $res = json_decode($fn->arguments, true);
            $others = [];

            if(isset($res['others']))
            {
                foreach($res['others'] as $o)
                {
                    $others[] = [
                        'id' => $o
                    ];
                }
    
                $messages = [
                    [
                        'id' => Str::uuid(),
                        'role' => 'assistant',
                        'content' => $res['message']
                    ],
                    [
                        'id' => Str::uuid(),
                        'role' => 'assistant',
                        'content' => null,
                        'metaData' => [
                            'listing' => [
                                'suggested' => [
                                    'id' => $res['suggested']
                                ],
                                'others' => $others
                            ]
                        ]
                    ],
                    [
                        'id' => Str::uuid(),
                        'role' => 'assistant',
                        'content' => $res['follow_up']
                    ],
                ];
            }

            $this->appendMessages($req, $messages);

            return $res;
        }
    
        return [
            'message' => 'Sorry, no listings found.',
            'suggested' => null,
            'others' => null,
            'follow_up' => 'Please adjust your search query.'
        ];
    }

    public function suggestedAgents(array $thread, array $agents, $req)
    {
        $systemMessage = [
            'role' => 'system',
            'content' => <<<SYSTEM
            You are a helpful, friendly assistant for Filipino Homes. 
            From the provided agents, pick the single best agent as 'suggested' and 2-5 alternatives as 'others'. 
            Return ONLY JSON with IDs and a concise message. 
            If no agents match, indicate it in the message and leave 'suggested' and 'others' empty.

            RULES:
            - Always focus ONLY on the MOST RECENT user intent.
            - Ignore previous messages if they conflict with the latest message.
            - Each agent carries PROVEN PERFORMANCE facts from Filipino Homes' own records: `area_listings` (live listings in the area the user asked about), `rating` (average stars and review count), `response` (median first-reply minutes, % answered within an hour, % left unanswered), `natcon` (years the agent was a NATCON awardee — Leuterio Realty's annual top-performer recognition — with the award segment when known), `awards`, `years_active`. Rank by these — most area listings and best responsiveness first, then rating and awards — not by name order. Say WHY the pick is best using those numbers (e.g. "12 live listings in Lahug, replies in about 20 minutes, NATCON 2026 awardee"). Never invent a number that is not in the data; skip a fact that is null.
            SYSTEM
        ];

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'functions' => [
                [
                    'name' => 'pick_agents',
                    'description' => 'Pick the best agent and other alternatives',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => [
                                'type' => 'string',
                                'description' => <<<DESC
                                A concise but informative message describing the selected agent. 
                                Include key details: name, location/address, number of listings (and listings in the asked area), rating, reply speed and awards when present, and contact info (email + mobile). 
                                Keep tone natural, helpful, and conversational. 
                                Use under 3-4 sentences for normal messages. 

                                Formatting rules:
                                - If helpful, structure the message with short headers or bullet points.
                                - Bold key details using Markdown-style double asterisks (**).
                                - Example:
                                **Agent Details**
                                - **Name:** Juan Dela Cruz
                                - **Location:** Cebu City
                                - **Listings:** 12 properties
                                - **Contact:** juan@example.com, 09171234567
                                - If no agents are found, clearly state that and suggest refining the search.
                                - Details of top listings.
                                DESC
                            ],
                            'follow_up' => [
                                'type' => 'string',
                                'description' => 'A short, context-aware follow-up message (e.g., suggest contacting the agent or viewing listings). Keep it very concise.'
                            ],
                            'suggested' => [
                                'type' => ['integer', 'null'],
                                'description' => 'ID of the best matching agent, or null if none found'
                            ],
                            'others' => [
                                'type' => ['array', 'null'],
                                'items' => ['type' => 'integer'],
                                'description' => 'IDs of alternative agents, or null if none found'
                            ]
                        ],
                        'required' => ['message', 'suggested', 'others', 'follow_up']
                    ]
                ]
            ],
            'function_call' => ['name' => 'pick_agents'],
            'messages' => array_merge(
                [$systemMessage],
                $thread,
                [
                    [
                        'role' => 'user',
                        'content' => "Here are the available agents:\n" . json_encode($agents, JSON_PRETTY_PRINT)
                    ]
                ]
            ),
        ]);

        $fn = $response->choices[0]->message->functionCall ?? null;

        if ($fn && isset($fn->arguments)) {
            $res = json_decode($fn->arguments, true);
            $others = [];

            foreach($res['others'] as $o)
            {
                $others[] = [
                    'id' => $o
                ];
            }

            $messages = [
                [
                    'id' => Str::uuid(),
                    'role' => 'assistant',
                    'content' => $res['message']
                ],
                [
                    'id' => Str::uuid(),
                    'role' => 'assistant',
                    'content' => null,
                    'metaData' => [
                        'agent' => [
                            'suggested' => [
                                'id' => $res['suggested']
                            ],
                            'others' => $others
                        ]
                    ]
                ],
                [
                    'id' => Str::uuid(),
                    'role' => 'assistant',
                    'content' => $res['follow_up']
                ],
            ];
            $this->appendMessages($req, $messages);

            return $res;
        }

        return [
            'message' => 'Sorry, no agents found.',
            'suggested' => null,
            'others' => null,
            'follow_up' => 'Try refining your search or specify location or expertise.'
        ];
    }

    public function classifyMessage(array $thread)
    {
        $prompt = <<<PROMPT
        You are a real estate intent classifier for Filipino Homes (Philippines only).
        
        TASK:
        Classify the MOST RECENT user message.
        
        CONTEXT:
        - Consider previous messages only if needed
        - Ignore messages unrelated to Filipino Homes real estate
        
        INTENT:
        - price → user asks what properties COST in an area: price range, average/typical price, going rate, "magkano", "how much is a studio in Lahug", rent levels — a market question, not a request to see specific listings
        - afford → user asks what they can AFFORD or what price range fits a monthly payment, salary or budget ("what can I afford at 25k a month", "my salary is 60k, what house can I buy", "magkano ang kaya ko", "how much house for 30k monthly amortization") — a budget-to-price question, not a request for listings
        - worth → user asks the VALUE of a property THEY OWN or want to sell / rent out ("what is my condo worth", "how much can I sell my house in Lahug for", "magkano mabenta ang bahay ko", "appraise my 200 sqm lot in Mandaue", "how much could I rent out my 2BR for") — a valuation, not a search
        - cost → user asks the TOTAL or true COST, fees, taxes, closing costs, cash needed up front, or the monthly payment OF BUYING a specific property or a stated price ("what are the fees when buying a 5M condo", "total cost of buying listing #123", "how much cash do I need upfront for a 4M house", "monthly amortization for a 3M condo over 20 years") — arithmetic on a price, not a budget question and not a search
        - rentbuy → user asks whether to RENT OR BUY in a place ("should I rent or buy in Lahug", "rent vs buy a condo in Cebu", "is it better to rent or to buy", "mag-rent o bumili") — a comparison of the two, not a search
        - project → user asks about a specific BUILDING, development or condo project by name ("tell me about Avida Towers Riala", "is 38 Park Avenue a good buy", "what's listed in Mivesa"), or to COMPARE two named developments ("Riala vs 38 Park Avenue")
        - area → user asks ABOUT a place in general: "tell me about Lahug", "what's it like in BGC", "is IT Park a good area to invest", "overview of Mandaue", "which barangays in Cebu City have the most listings", rental yield / ROI / "where do condos yield the most in Cebu" — an area or investment question, not a price-only question and not a request for listings
        - listing → user shows intent to buy, sell, rent, inquire, or search for property (explicit or implied)
        - agent → user shows intent to find, search, contact, or inquire about a real estate agent (explicit or implied)
        - normal → greetings, small talk, or anything NOT related to Filipino Homes real estate
        
        RULES:
        - Be strict
        - If unsure or off-topic, return: normal
        - If the user asks for a person (agent, broker, realtor), classify as: agent
        - If the user asks how much properties cost / the price range / average price / rate in a place, classify as: price
        - If the user asks what a place is like, for an overview / snapshot / profile of an area or neighbourhood, or whether an area is good, classify as: area
        - If the user names a specific building / development / condo project (not a city or barangay) and asks about it or compares two of them, classify as: project
        - If the user asks for property (house, condo, lot, rent, location) or to see/find/show listings, classify as: listing
        - A stated budget while looking for property ("condo in Makati under 5M") is: listing — not price
        - A MONTHLY amount or a salary asked against what they can buy ("at 25k a month", "my income is 60k") is: afford
        - "What is MY property worth" / what they could sell or rent THEIRS for is: worth — not price (price is what OTHER listings cost)
        - Fees, taxes, closing costs, cash up front or the monthly payment for a GIVEN price or listing is: cost — not afford
        - "Rent or buy" in a place is: rentbuy — not area and not listing
        - Ignore emojis, filler words, repeated characters
        
        OUTPUT:
        - Return EXACTLY one word: price OR area OR project OR afford OR worth OR cost OR rentbuy OR listing OR agent OR normal
        - No explanation
        - No punctuation
        - No extra text
        PROMPT;
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => array_merge(
                [
                    [
                        'role' => 'system', // ✅ important fix
                        'content' => $prompt,
                    ],
                ],
                $thread // ✅ flatten into messages
            ),
        ]);
    
        $classification = trim(strtolower($response->choices[0]->message->content ?? ''));
    
        if (!in_array($classification, ['normal', 'listing', 'agent', 'price', 'area', 'project', 'afford', 'worth', 'cost', 'rentbuy'])) {
            return 'normal';
        }
    
        return $classification;
    }

    /**
     * The developments a project question names — one, or two to compare — and the city if said.
     *
     * @return array{projects: array<int, string>, city: string}
     */
    public function extractProjects(array $thread): array
    {
        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => 'You extract the names of specific real-estate developments (condo towers, subdivisions, projects) from the MOST RECENT user message for Filipino Homes, Philippines. Return each name as the user wrote it, without the city. Up to two names (two means a comparison). Leave the city empty unless named.',
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_projects',
                    'description' => 'The named developments and optional city.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'projects' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Development names, e.g. ["Avida Towers Riala", "38 Park Avenue"]. Empty if none.'],
                            'city' => ['type' => 'string', 'description' => 'City if named, else "".'],
                        ],
                        'required' => ['projects', 'city'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_projects'],
            ]);
            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];
            $names = array_values(array_filter(array_map(fn ($n) => is_string($n) ? trim($n) : '', (array) ($args['projects'] ?? []))));

            return ['projects' => array_slice($names, 0, 2), 'city' => is_string($args['city'] ?? null) ? trim($args['city']) : ''];
        } catch (\Throwable) {
            return ['projects' => [], 'city' => ''];
        }
    }

    public function parsePropertyQuery(array $thread)
    {
        $systemMessage = [
        'role' => 'system',
        'content' => <<<SYSTEM
        You are a Filipino real estate assistant for Filipino Homes.

        Your task is to extract structured property search filters from a conversation thread.

        IMPORTANT RULES:
        1. ALWAYS use the MOST RECENT user message as the main source of intent.
        2. Include context from earlier messages ONLY IF it directly refines or narrows the latest message. Ignore any unrelated topics from previous messages.
        3. Only ignore previous context if the user clearly changes:
        - property name
        - location
        - property type
        - or asks a completely different question
        3. Only extract relevant real estate information for Filipino property listings.
        4. If information is NOT mentioned, return:
        - empty string "" for property_type or property_subtype
        - null or 0 for numeric attributes
        5. Lot and floor area must be in square meters.
        6. Do NOT include lot_area for:
        - Condominium
        - Commercial (unless clearly stated)
        7. Keep values realistic for Philippine real estate.
        7b. Write places as their full names, never abbreviations or parentheses: "CDO" → "Cagayan de Oro", "BGC" → "Taguig City", "QC" → "Quezon City", "GenSan" → "General Santos City", "MNL" → "Manila".

        ADDITIONAL RULES:
        8. Generate a keyword (query_words) based ONLY on the latest user intent. Pick the most relevant word: property type or key feature. DO NOT USE THE LOCATION.
        9. Adjust the price range attributes if the user mentions a budget:
        - price_min = 0
        - price_max = the user’s stated budget
        10. Keep other numeric attributes 0 if not specified.
        11. Extract date range if mentioned:
        - date_from and date_to must be formatted as Y-m-d
        - If user says:
        • "latest", "recent", "new" → prioritize newer listings → set date_from to a recent date (e.g. last 60 days), date_to = today
        • "old", "earliest" → prioritize older listings → set date_to to an older date (e.g. 1–2 years ago), date_from = ""
        • specific dates → convert to Y-m-d
        - If no date mentioned → return empty string ""
        13. If the user asks for listings whose price was REDUCED / dropped / cut / discounted ("price drops this month", "reduced price condos", "nagbaba ang presyo") set `price_dropped_days` to the window in days (this week → 7, this month → 30, otherwise 30). 0 when not asked.
        12. If the user wants to be NEAR a specific place — a mall, business park, school, hospital, office, church, beach, a named building or development ("near IT Park", "walking distance to Ayala Center Cebu", "close to SM Seaside", "beside Cebu Doctors") — put that place's name in `near` (just the place, no "near"), and the distance in `radius_km` when they say one ("within 2 km" → 2; "walking distance" → 1; otherwise 0). Do NOT put the place in query_words or address; the city still goes in address when named.
        SYSTEM
        ];
    
        // Merge system message with the conversation thread
        $messages = array_merge([$systemMessage], $thread);
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => $messages,
            'functions' => [
                [
                    'name' => 'extract_property',
                    'description' => 'Extract property search filters based ONLY on the latest relevant intent.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'property_type' => [
                                'type' => 'string',
                                'enum' => ["Condominium", "House", "Land", "Commercial"],
                                'description' => 'The main property type category. Return an empty string "" if not MENTIONED.',
                            ],
                            'property_subtype' => [
                                'type' => 'string',
                                'description' => 'The subtype must correspond to the selected property_type. ' .
                                    'Condominium subtypes: Penthouse, Studio, 1 Bedroom, 2 Bedrooms, 3 Bedrooms, 4 Bedrooms, Loft. ' .
                                    'House subtypes: Apartment, Townhouse, House and Lot, Boarding House, Retirement House, Pension House, Beach House / Resort. ' .
                                    'Land subtypes: Agricultural Lot, Island, Residential Lot, Commercial Lot, Memorial, Beach Lot, Industrial Lot. ' .
                                    'Commercial subtypes: Warehouse, BPO, Office, Building, Hotel, Space.
                                    Return an empty string "" if not MENTIONED.',
                                'enum' => [
                                    // Condominium
                                    "Penthouse", "Studio", "1 Bedroom", "2 Bedrooms", "3 Bedrooms", "4 Bedrooms", "Loft",
                                    // House
                                    "Apartment", "Townhouse", "House and Lot", "Boarding House", "Retirement House", "Pension House", "Beach House / Resort",
                                    // Land
                                    "Agricultural Lot", "Island", "Residential Lot", "Commercial Lot", "Memorial", "Beach Lot", "Industrial Lot",
                                    // Commercial
                                    "Warehouse", "BPO", "Office", "Building", "Hotel", "Space",
                                ],
                            ],
                            'query_words' => [
                                'type' => 'string',
                                'description' => <<<DESC
                                Focus only on:
                                - Name of the property or a listing
                                - Property title
                                - Property type (condo, studio, house)
                                - Essential features (furnished, parking, near beach)
                                Do NOT include vague words like "alternative" or full sentences.
                                The result should be concise, relevant, and directly usable for searching property listings.
                                Select a reasonable choice.
                                Return an empty string "" if not MENTIONED.
                                DESC
                            ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'One of "For Sale", "For Rent", "Foreclosure" — "Foreclosure" when the user says foreclosed, foreclosure, bank-acquired or pasalo-free bank property; "For Sale" when they say buy, purchase, budget to buy; "For Rent" when they say rent, lease, monthly. Return "" when the message does not say which.'
                            ],
                            'address' => [
                                'type' => 'string',
                                'description' => 'City or location in the Philippines. Return empty string if not specified.'
                            ],
                            'near' => [
                                'type' => 'string',
                                'description' => 'A specific place the user wants to be near — mall, business park, school, hospital, office, named building/development (e.g. "IT Park", "Ayala Center Cebu", "SM Seaside", "Avida Towers Riala"). Just the place name. Empty string "" if none.'
                            ],
                            'radius_km' => [
                                'type' => 'number',
                                'description' => 'How close, in km, when the user says ("within 2 km" → 2, "walking distance" → 1). 0 if not said.'
                            ],
                            'price_dropped_days' => [
                                'type' => 'number',
                                'description' => 'When the user asks for listings whose price was reduced/dropped/cut: the window in days (this week → 7, this month → 30; default 30). 0 if not asked.'
                            ],
                            'agent_name' => [
                                'type' => 'string',
                                'description' => 'Name of the agent associated with this property request. Leave it blank if not specified.',
                            ],
                            'listings_count' => [
                                'type' => 'number',
                                'description' => 'Desired list of listings of an agent from a user request. Return 0 if not mentioned.',
                            ],
                            'date_from' => [
                                'type' => 'string',
                                'description' => 'Start date in Y-m-d format. Return empty string "" if not mentioned.',
                            ],
                            'date_to' => [
                                'type' => 'string',
                                'description' => 'End date in Y-m-d format. Return empty string "" if not mentioned.',
                            ],
                            'attributes' => [
                                'type' => 'object',
                                'properties' => [
                                    'price_min' => ['type' => 'number'],
                                    'price_max' => ['type' => 'number'],
                                    'lot_area' => ['type' => 'number'],
                                    'floor_area' => ['type' => 'number'],
                                    'beds' => ['type' => 'number'],
                                    'baths' => ['type' => 'number'],
                                    'parking' => ['type' => 'number'],
                                ],
                                'required' => ['beds', 'baths', 'parking', 'lot_area', 'floor_area', 'price_min', 'price_max'],
                            ],
                        ],
                        'required' => [
                            'property_type', 
                            'category', 
                            'address', 
                            'query_words', 
                            'attributes', 
                            'property_subtype', 
                            'agent_name', 
                            'listings_count',
                            'date_from',
                            'date_to',
                            'near',
                            'radius_km',
                            'price_dropped_days'
                        ],
                    ],
                ],
            ],
            'function_call' => ['name' => 'extract_property'],
        ]);
    
        $fn = $response->choices[0]->message->functionCall ?? null;
    
        if (!$fn || empty($fn->arguments)) {
            return [
                'function' => 'extract_property',
                'arguments' => [
                    'property_type' => '',
                    'property_subtype' => '',
                    'address' => '',
                    'near' => '',
                    'radius_km' => 0,
                    'price_dropped_days' => 0,
                    'agent_name' => '',
                    'date_from' => '',
                    'date_to' => '',
                    'attributes' => [
                        'price_min' => null,
                        'price_max' => null,
                        'lot_area' => null,
                        'floor_area' => null,
                        'beds' => null,
                        'baths' => null,
                        'parking' => null,
                    ],
                ],
            ];
        }
    
        $args = json_decode($fn->arguments, true) ?: [];

        // The two newest filters are easy for the model to over-apply, and either one empties the
        // results when wrong — so each needs the user's own words in the latest message.
        $latest = mb_strtolower((string) (collect($thread)->where('role', 'user')->last()['content'] ?? ''));
        if (! preg_match('/\b(drop|dropped|reduc|cut|discount|lower(ed)?|slash|bawas|baba|mura)\w*/u', $latest)) {
            $args['price_dropped_days'] = 0;
        }
        if (! preg_match('/\b(near|close to|beside|next to|walking|within|around|by the|malapit|tabi)\b/u', $latest)) {
            $args['near'] = '';
            $args['radius_km'] = 0;
        }

        return [
            'function' => $fn->name,
            'arguments' => $args,
        ];
    }

    public function parseAgentQuery(array $thread)
    {
        $systemMessage = [
            'role' => 'system',
            'content' => <<<SYSTEM
            You are a Filipino real estate assistant for Filipino Homes.
    
            Your task is to extract structured agent search filters from a conversation thread.
    
            IMPORTANT RULES:
            1. ALWAYS use the MOST RECENT user message as the main source of intent.
            2. Include context from earlier messages ONLY IF it directly refines or narrows the latest message. Ignore any unrelated topics from previous messages.
            3. Only extract relevant real estate information for Filipino Homes agents.
            4. The "property_keyword" field must reflect a real estate type, e.g., "condo", "house", "land", "townhouse", "apartment".
            5. Provide empty strings or 0 if information is missing, but never return null.
            6. Write places as their full names, never abbreviations or parentheses: "CDO" → "Cagayan de Oro", "BGC" → "Taguig City", "QC" → "Quezon City", "GenSan" → "General Santos City", "MNL" → "Manila".
            7. A place the user wants the agent to WORK in ("agents in Lahug", "best agent for Davao condos") goes in listing_address; agent_address is only for where the agent is based.
            SYSTEM
        ];
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => array_merge([$systemMessage], $thread),
            'functions' => [
                [
                    'name' => 'extract_agent',
                    'description' => 'Extract agent search filters based ONLY on the latest relevant intent.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Full name of the agent.'
                            ],
                            'email' => [
                                'type' => 'string',
                                'description' => 'Agent email address.'
                            ],
                            'listings_count' => [
                                'type' => 'integer',
                                'description' => 'Number of listings the agent currently has, based on what the user mentions or implies in the conversation. Return 0 if unknown.'
                            ],
                            'agent_address' => [
                                'type' => 'string',
                                'description' => 'Any address mentioned by the user in the conversation thread related to the agent, such as a location the agent serves. Return an empty string if none is mentioned.'
                            ],
                            'listing_address' => [
                                'type' => 'string',
                                'description' => 'Any location or address mentioned by the user in the conversation thread related to the property or listing they are searching for. Return an empty string if none is mentioned.'
                            ],
                            'property_type' => [
                                'type' => 'string',
                                'enum' => ["Condominium", "House", "Land", "Commercial"],
                                'description' => 'The main property type category. Return an empty string "" if not MENTIONED.',
                            ],
                            'property_subtype' => [
                                'type' => 'string',
                                'description' => 'The subtype must correspond to the selected property_type. ' .
                                    'Condominium subtypes: Penthouse, Studio, 1 Bedroom, 2 Bedrooms, 3 Bedrooms, 4 Bedrooms, Loft. ' .
                                    'House subtypes: Apartment, Townhouse, House and Lot, Boarding House, Retirement House, Pension House, Beach House / Resort. ' .
                                    'Land subtypes: Agricultural Lot, Island, Residential Lot, Commercial Lot, Memorial, Beach Lot, Industrial Lot. ' .
                                    'Commercial subtypes: Warehouse, BPO, Office, Building, Hotel, Space.
                                    Return an empty string "" if not MENTIONED.',
                                'enum' => [
                                    // Condominium
                                    "Penthouse", "Studio", "1 Bedroom", "2 Bedrooms", "3 Bedrooms", "4 Bedrooms", "Loft",
                                    // House
                                    "Apartment", "Townhouse", "House and Lot", "Boarding House", "Retirement House", "Pension House", "Beach House / Resort",
                                    // Land
                                    "Agricultural Lot", "Island", "Residential Lot", "Commercial Lot", "Memorial", "Beach Lot", "Industrial Lot",
                                    // Commercial
                                    "Warehouse", "BPO", "Office", "Building", "Hotel", "Space",
                                ],
                            ],
                        ],
                        'required' => ['name', 'email', 'listings_count', 'agent_address', 'listing_address', 'property_type', 'property_subtype']
                    ]
                ]
            ],
            'function_call' => ['name' => 'extract_agent'],
        ]);
    
        $fn = $response->choices[0]->message->functionCall ?? null;
    
        if (!$fn || empty($fn->arguments)) {
            return [
                'function' => 'extract_agent',
                'arguments' => [
                    'name' => '',
                    'agent_address' => '',
                    'email' => '',
                    'listings_count' => 0,
                    'listing_address' => '',
                    'property_type' => '',
                    'property_subtype' => ''
                ],
            ];
        }
    
        $decoded = json_decode($fn->arguments, true);
    
        return [
            'function' => $fn->name ?? 'extract_agent',
            'arguments' => [
                'name' => $decoded['name'] ?? '',
                'agent_address' => $decoded['agent_address'] ?? '',
                'email' => $decoded['email'] ?? '',
                'listings_count' => $decoded['listings_count'] ?? 0,
                'listing_address' => $decoded['listing_address'] ?? '',
                'property_type' => $decoded['property_type'] ?? '',
                'property_subtype' => $decoded['property_subtype'] ?? ''
            ],
        ];
    }

    public function parseListingQuery($query)
    {
        $prompt = "";

        $response = $this->client->chat()->create([
            'model' => 'gpt-5.4-mini',
            'messages' => array_merge(
                [
                    [
                        'role' => 'system',
                        'content' => $prompt,
                    ],
                ],
            ),
        ]);
    }
}