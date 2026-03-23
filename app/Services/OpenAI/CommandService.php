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
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
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
                                Include key details: name, location/address, number of listings, and contact info (email + mobile). 
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
        - listing → user shows intent to buy, sell, rent, inquire, or search for property (explicit or implied)
        - agent → user shows intent to find, search, contact, or inquire about a real estate agent (explicit or implied)
        - normal → greetings, small talk, or anything NOT related to Filipino Homes real estate
        
        RULES:
        - Be strict
        - If unsure or off-topic, return: normal
        - If the user asks for a person (agent, broker, realtor), classify as: agent
        - If the user asks for property (house, condo, lot, rent, price, location), classify as: listing
        - Ignore emojis, filler words, repeated characters
        
        OUTPUT:
        - Return EXACTLY one word: listing OR agent OR normal
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
    
        if (!in_array($classification, ['normal', 'listing', 'agent'])) {
            return 'normal';
        }
    
        return $classification;
    }

    public function parsePropertyQuery(array $thread)
    {
        $systemMessage = [
        'role' => 'system',
        'content' => <<<SYSTEM
        You are a Filipino real estate assistant for Filipino Homes.

        Your task is to extract structured property search filters from a conversation thread.

        IMPORTANT RULES:
        1. ALWAYS prioritize the MOST RECENT user intent.

        2. HOWEVER, preserve relevant context from earlier messages IF:
        - The user is continuing the same search
        - The new message is a refinement (e.g. "for rent", "2BR", "cheapest")

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
                                'description' => 'Property for rent or for sale. Return an empty "" as default, otherwise if Specify (For Sale or For Rent) values.'
                            ],
                            'address' => [
                                'type' => 'string',
                                'description' => 'City or location in the Philippines. Return empty string if not specified.'
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
                            'date_to'
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
    
        return [
            'function' => $fn->name,
            'arguments' => json_decode($fn->arguments, true),
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
            1. ALWAYS focus on the MOST RECENT user intent in the thread.
            2. Ignore previous context if the topic changes or the latest message is unrelated.
            3. Only extract relevant real estate information for Filipino Homes agents.
            4. The "property_keyword" field must reflect a real estate type, e.g., "condo", "house", "land", "townhouse", "apartment".
            5. Provide empty strings or 0 if information is missing, but never return null.
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
}