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

    public function classifyMessage(string $message): string
    {
        $prompt = <<<PROMPT
    You are a real estate assistant. Determine if the user message is a property inquiry about Filipino homes/real estate listings. 
    
    Return exactly one word:
    - "inquired" if it is about property inquiry (buying, selling, renting, searching homes, condos, lots, etc. in the Philippines)
    - "normal" if it is just a casual chat, greetings, or unrelated to Filipino real estate.
    
    Message: "{$message}"
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

    public function parsePropertyQuery(string $query, bool $defaultAll = false): array
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a Filipino real estate assistant. Extract structured property search filters. Return lot and floor area in square meters. Price, beds, baths, parking should be reasonable defaults if not specified.'
                ],
                [
                    'role' => 'user',
                    'content' => $query
                ],
            ],
            'functions' => [
                [
                    'name' => 'extract_property',
                    'description' => 'Return an array of properties with subtypes and attributes. If the query is generic, include all property types and subtypes with default attributes. If the query is specific, filter only the relevant types/subtypes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'properties' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'property_type' => [
                                            'type' => 'string',
                                            'enum' => ["Condominium", "House", "Land", "Commercial"]
                                        ],
                                        'property_subtype' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => [
                                                        'type' => 'string',
                                                        'enum' => [
                                                            "Penthouse","Studio","1 Bedroom","2 Bedrooms","3 Bedrooms",
                                                            "4 Bedrooms","Loft","Apartment","Townhouse","House and Lot",
                                                            "Boarding House","Retirement House","Pension House",
                                                            "Beach House / Resort","Agricultural Lot","Island",
                                                            "Residential Lot","Commercial Lot","Memorial","Beach Lot",
                                                            "Industrial Lot","Warehouse","BPO","Office","Building",
                                                            "Hotel","Space"
                                                        ]
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
                                                        'required' => ['beds','baths','parking','lot_area','floor_area','price_min','price_max']
                                                    ]
                                                ],
                                                'required' => ['name','attributes']
                                            ]
                                        ]
                                    ],
                                    'required' => ['property_type','property_subtype']
                                ]
                            ]
                        ],
                        'required' => ['properties']
                    ]
                ]
            ],
            'function_call' => ['name' => 'extract_property']
        ]);
    
        $fn = $response->choices[0]->message->functionCall ?? null;
    
        if (!$fn) {
            return [];
        }
    
        $parsed = json_decode($fn->arguments, true);
    
        // If defaultAll = true, return everything. Otherwise, filter if suggested
        if (!$defaultAll && isset($parsed['properties'])) {
            $queryLower = strtolower($query);
            $filtered = [];
    
            foreach ($parsed['properties'] as $prop) {
                $matchesType = str_contains(strtolower($prop['property_type']), $queryLower);
                $matchesSubtype = array_filter($prop['property_subtype'], function ($sub) use ($queryLower) {
                    return str_contains(strtolower($sub['name']), $queryLower);
                });
    
                if ($matchesType || !empty($matchesSubtype)) {
                    $filtered[] = [
                        'property_type' => $prop['property_type'],
                        'property_subtype' => !empty($matchesSubtype) ? array_values($matchesSubtype) : $prop['property_subtype']
                    ];
                }
            }
    
            return $filtered;
        }
    
        return $parsed['properties'] ?? [];
    }
}