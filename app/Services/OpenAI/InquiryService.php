<?php

namespace App\Services\OpenAI;

use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InquiryService
{
    private $client;
    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function streamMessage(string $message)
    {
        return new StreamedResponse(function () use ($message) {
            // Simulate AI typing by splitting the message into words or characters
            $words = preg_split('/(\s+)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
    
            foreach ($words as $word) {
                if (trim($word) === '') {
                    echo "data: " . json_encode(['text' => $word]) . "\n\n";
                } else {
                    // Wrap word with slight delay to simulate typing
                    echo "data: " . json_encode(['text' => $word . ' ']) . "\n\n";
                }
                ob_flush();
                flush();
                usleep(50000); // 50ms delay per word
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

    public function replyNormal(array $thread)
    {    
        return new StreamedResponse(function () use ($thread) {
            $stream = $this->client->chat()->createStreamed([
                'model' => 'gpt-4-turbo',
                'messages' => array_merge(
                    [
                        [
                            'role' => 'system',
                            'content' => <<<PROMPT
        You are a friendly and professional real estate assistant for Filipino Homes.
        
        BEHAVIOR RULES:
        - Always focus on the MOST RECENT user message, while considering previous context.
        - Maintain a natural, conversational tone (not robotic).
        - Keep responses concise but helpful.
        - Always stay within real estate topics in the Philippines.
        - If the user changes location or preference, adapt immediately.
        - If the request is vague, ask a short follow-up question.
        - When appropriate, guide the user toward property listings.
        
        DO NOT:
        - Go off-topic.
        - Repeat the same phrases.
        - Over-explain.
        
        GOAL:
        Make the conversation feel like a real property agent helping the user find the right property.
        PROMPT
                        ]
                    ],
                    $thread
                ),
            ]);
    
            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';
                if ($text) {
                    echo "data: " . json_encode(['text' => $text]) . "\n\n";
                    ob_flush();
                    flush();
                }
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

    public function replySearchingStream(array $thread)
    {
        return new StreamedResponse(function () use ($thread) {
    
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
                'model' => 'gpt-4-turbo',
                'messages' => $messages,
            ]);

            echo "data: " . json_encode([
                'type' => 'mode',
                'mode' => 'searching'
            ]) . "\n\n";
            ob_flush();
            flush();
    
            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';
    
                if (!empty($text)) {
                    echo "data: " . json_encode([
                        'type' => 'text',
                        'text' => $text
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                }
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
    
    public function replyInquiredStream(string $thread, array $listings)
    {
        return new StreamedResponse(function () use ($thread, $listings) {
            $client = OpenAI::client(env('OPENAI_API_KEY'));
            $instruction = <<<INSTRUCTION
            You are a helpful assistant for a Filipino real estate website called Filipino Homes. 
            The user asked: "{$thread}".
            You are given a list of property listings from the database. Each listing includes:
            - property_type
            - property_subtype (array of objects with name and attributes)
            - attributes: price_min, price_max, lot_area, floor_area, beds, baths, parking
            - agent info: name, mobile, email
            - link

            Analyze all listings and select **the single listing that best matches the user's query**. 
            Reply politely and naturally, like ChatGPT, summarizing **only the best listing** with:
            - property type and subtype
            - price range
            - floor area and lot area
            - beds, baths, parking
            - agent name, mobile, email
            - link

            Make your reply concise, clear, and user-friendly.
            Encourage the user to inquire further or visit the website for more details. 
            Do not include any other listings or irrelevant information.
            INSTRUCTION;

            $stream = $client->chat()->createStreamed([
                'model' => 'gpt-4-turbo',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $instruction . "\n\nListings:\n" . json_encode($listings, JSON_PRETTY_PRINT)
                    ]
                ],
            ]);

            foreach ($stream as $chunk) {
                $text = $chunk->choices[0]->delta->content ?? '';
                if ($text) {
                    // Stream chunks for front-end display
                    echo "data: " . json_encode(['text' => $text]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }

            echo "data: [DONE]\n\n";
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function replyInquired(string $thread, array $listings): string
    {
        // Instruction for the AI
        $instruction = <<<INSTRUCTION
    You are a helpful assistant for a Filipino real estate website called Filipino Homes. 
    The user asked: "{$thread}".
    
    You are given a list of property listings from the database. Each listing includes:
    - property_type
    - property_subtype (array of objects with name and attributes)
    - attributes: price_min, price_max, lot_area, floor_area, beds, baths, parking
    
    Reply politely and naturally, summarizing up to 5 of the listings for the user. 
    Include the property type, subtype, price range, floor and lot area, beds, baths, and parking. 
    Make it readable as a message, not a raw table. 
    Do not include listings beyond the top 5. 
    Encourage the user to inquire further or visit the website for more details.
    INSTRUCTION;
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $instruction . "\n\nListings:\n" . json_encode($listings, JSON_PRETTY_PRINT)
                ]
            ],
        ]);
    
        return $response->choices[0]->message->content ?? 
               "Hello! I found some listings that might interest you. Please visit Filipino Homes for more details.";
    }

    public function suggestedListing(array $thread, array $listings)
    {
        // Prepare system instructions
        $systemMessage = [
            'role' => 'system',
            'content' => <<<SYSTEM
    You are a helpful, friendly assistant for Filipino Homes. 
    From the provided property listings, pick the single best listing as 'suggested' and 2-3 alternative options as 'others'. 
    Return ONLY JSON with IDs and a concise message. 
    If no listings match, indicate it in the message and leave 'suggested' and 'others' empty.
    RULE: Based on the conversation thread, always focus on the MOST RECENT user intent. 
    SYSTEM
        ];
    
        // Merge system + conversation thread
        $messages = array_merge([$systemMessage], $thread);
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
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
                                'description' => 'A concise but informative message describing the selected listing. Include key property details such as property type, location, price, and notable features. Also include the assigned agent’s name, email, and mobile number. Keep it natural, helpful, and under 3-4 sentences. If no listings are found, clearly state that and suggest refining the search.'
                            ],
                            'follow_up' => [
                                'type' => 'string',
                                'description' => 'A context-aware follow-up message. For example, suggesting viewing, contacting the agent, or showing alternatives. Should feel natural and specific to the actual listing. Make it shorter and very precise.'
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
            return json_decode($fn->arguments, true);
        }
    
        return [
            'message' => 'Sorry, no listings found.',
            'suggested' => null,
            'others' => null,
            'follow_up' => 'Please adjust your search query.'
        ];
    }

    public function classifyMessage(array $thread)
    {
        $prompt = <<<PROMPT
        You are a real estate assistant for Filipino Homes.
        
        TASK:
        Classify the MOST RECENT user message in the conversation.
        
        RULES:
        - Always focus on the latest user intent, but consider previous context if needed.
        
        RETURN EXACTLY ONE WORD ONLY:
        - "inquired" → if the user is asking about buying, selling, renting, or searching for properties in the Philippines.
        - "normal" → if it is casual chat, greetings, or unrelated to real estate.
        
        DO NOT explain.
        DO NOT add punctuation.
        DO NOT return anything else.
        PROMPT;
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
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
    
        if (!in_array($classification, ['normal', 'inquired'])) {
            return 'normal';
        }
    
        return $classification;
    }

    public function parsePropertyQuery(array $thread)
    {
        $systemMessage = [
        'role' => 'system',
        'content' => <<<SYSTEM
        You are a Filipino real estate assistant.

        Your task is to extract structured property search filters from a conversation thread.

        IMPORTANT RULES:
        1. ALWAYS focus on the MOST RECENT user intent in the thread.
        2. Ignore previous context if the topic changes (new location, type, or unrelated message).
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
        8. Generate a **single keyword** (query_word) based ONLY on the latest user intent. Pick the most relevant word: property type, key feature, or location. Output exactly one word.
        9. Adjust the price range attributes if the user mentions a budget:
        - price_min = 0
        - price_max = the user’s stated budget
        10. Keep other numeric attributes 0 if not specified.

        SYSTEM
        ];
    
        // Merge system message with the conversation thread
        $messages = array_merge([$systemMessage], $thread);
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
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
                            ],
                            'property_subtype' => [
                                'type' => 'string',
                                'enum' => [
                                    "Penthouse", "Studio", "1 Bedroom", "2 Bedrooms", "3 Bedrooms",
                                    "4 Bedrooms", "Loft", "Apartment", "Townhouse", "House and Lot",
                                    "Boarding House", "Retirement House", "Pension House",
                                    "Beach House / Resort", "Agricultural Lot", "Island",
                                    "Residential Lot", "Commercial Lot", "Memorial", "Beach Lot",
                                    "Industrial Lot", "Warehouse", "BPO", "Office", "Building",
                                    "Hotel", "Space",
                                ],
                            ],
                            'query_word' => [
                                'type' => 'string',
                                'description' => <<<DESC
                                Generate a single, keyword-rich string (5–12 words) based on the user’s latest intent.
                                Focus only on:
                                - Property type (condo, studio, house)
                                - Key location (city, barangay, subdivision)
                                - Essential features (furnished, parking, near beach)
                                Do NOT include vague words like "alternative" or full sentences.
                                The result should be concise, relevant, and directly usable for searching property listings.
                                - USE ONE WORD ONLY, AND SELECT A REASONABLE CHOICE.
                                DESC
                            ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'Property for rent or for sale. Return a For Sale as default, otherwise if Specify (For Sale or For Rent) values.'
                            ],
                            'address' => [
                                'type' => 'string',
                                'description' => 'City or location in the Philippines. Return empty string if not specified.'
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
                        'required' => ['property_type', 'category', 'address', 'query_word', 'attributes', 'property_subtype'],
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
}