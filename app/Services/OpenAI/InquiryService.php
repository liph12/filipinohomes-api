<?php

namespace App\Services\OpenAI;

use OpenAI;

class InquiryService
{
    private $client;
    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function replyNormal(string $thread): string
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => <<<PROMPT
    You are an assistant for a Filipino real estate website called Filipino Homes. 
    Reply politely and casually to this message: "{$thread}".
    Always keep the reply relevant to Filipino Homes and real estate inquiries in the Philippines. 
    You can greet the user, acknowledge their message, and gently guide them toward property listings if appropriate.
    Do not talk about unrelated topics.
    PROMPT
                ]
            ],
        ]);
    
        return $response->choices[0]->message->content ?? 
               "Hello! Welcome to Filipino Homes. How can I help you find your dream property today?";
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

    public function classifyMessage(string $thread)
    {
        $prompt = <<<PROMPT
    You are a real estate assistant. Determine if the user message is a property inquiry about Filipino homes/real estate listings. 
    
    Return exactly one word:
    - "inquired" if it is about property inquiry (buying, selling, renting, searching homes, condos, lots, etc. in the Philippines)
    - "normal" if it is just a casual chat, greetings, or unrelated to Filipino real estate.
    
    Message: "{$thread}"
    PROMPT;
    
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);
    
        $classification = trim(strtolower($response->choices[0]->message->content ?? ''));
    
        if (!in_array($classification, ['normal', 'inquired'])) {
            return 'normal';
        }
    
        return $classification;
    }

    public function parsePropertyQuery(string $thread)
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract structured property search filters based on allowed categories and subtypes',
                ],
                [
                    'role' => 'user',
                    'content' => $thread,
                ],
            ],
            'functions' => [
                [
                    'name' => 'extract_property',
                    'description' => 'Extract property search filters. Suggest a reasonable attributes, make it normal. Lot and floor area should be returned in square meters.',
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
                            'address' => ['type' => 'string'],
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
                        'required' => ['property_type', 'address', 'attributes'],
                    ],
                ],
            ],
            'function_call' => ['name' => 'extract_property'],
        ]);

        $fn = $response->choices[0]->message->functionCall ?? null;

        return [
            'function' => $fn->name,
            'arguments' => json_decode($fn->arguments, true),
        ];
    }
}