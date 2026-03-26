<?php

namespace App\Services\OpenAI;

use OpenAI;

class ListingCommandService
{
    protected $client;
    protected array $taxonomy;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));

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
                        'description' => <<<DESC
                        Extract a short, keyword-based search phrase from the query.
                        
                        Guidelines:
                        - Include relevant property keywords such as:
                          - property type (e.g. condo, house, studio, townhouse)
                          - subtype (e.g. 2 bedroom, loft, office)
                          - key features (e.g. furnished, beachfront, parking, near mall)
                          - property name if mentioned
                        - Keep it SHORT (2–6 words only).
                        - Do NOT include full sentences.
                        - Do NOT include filler or vague words like "looking for", "alternative", etc.
                        - Combine terms naturally (e.g. "2 bedroom condo furnished", "beach house", "office space BGC").
                        
                        IMPORTANT:
                        - If ANY relevant keyword exists, you MUST return a search string.
                        - Only return "" if the query truly has no meaningful searchable terms.
                        
                        Examples:
                        - "Looking for a 2 bedroom condo in Cebu" → "2 bedroom condo"
                        - "Affordable house and lot" → "house and lot"
                        - "Office space for rent BGC" → "office space BGC"
                        DESC
                    ],
                    'address' => ['type'=>'string']
                ],
                'required' => [
                    'categories','types','subtypes','furnishings','amenities',
                    'beds','baths','priceMin','priceMax','sqmMin','sqmMax','search','address'
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