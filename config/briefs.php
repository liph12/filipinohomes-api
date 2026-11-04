<?php

/*
|--------------------------------------------------------------------------
| Buyers & Sellers Link — the two client briefs
|--------------------------------------------------------------------------
|
| Every agent has one permanent link with two public pages on the site:
| /buy-with/{slug} for buyers and /sell-with/{slug} for owners who want to
| sell. Each is a four-step brief; the answers land on the agent's Buyers &
| Sellers Link page and in an email to the agent.
|
| This file is the ONE definition of both briefs: the steps, every choice
| question with its option keys and labels, which questions are required,
| which only apply after another answer (`show_when`), and the free-text
| fields with their caps. The API validates and narrows every submission
| against it (App\Support\Briefs) and serves it to the site as JSON
| (GET /api/briefs/questions), so the forms, the dashboard, the exports and
| the email all read from the same words. Stored answers are option KEYS,
| never labels, so a label can be reworded without touching old briefs.
|
| Options are [key, label] pairs. `show_when` is ['key' => ..., 'in' => [...]]
| or ['key' => ..., 'not_in' => [...]]; a question whose rule fails is hidden
| on the form and dropped from the stored answers. Parents must come before
| their children in the question order.
*/

$areas = [
    ['metro_manila', 'Metro Manila'],
    ['cebu', 'Cebu'],
    ['davao', 'Davao'],
    ['iloilo', 'Iloilo'],
    ['bacolod', 'Bacolod'],
    ['cdo', 'Cagayan de Oro'],
    ['pampanga', 'Pampanga / Clark'],
    ['cavite', 'Cavite'],
    ['laguna', 'Laguna'],
    ['batangas', 'Batangas'],
    ['bohol', 'Bohol'],
    ['baguio', 'Baguio'],
    ['palawan', 'Palawan'],
];

$propertyTypes = [
    ['house_lot', 'House and Lot'],
    ['condo', 'Condominium'],
    ['townhouse', 'Townhouse'],
    ['lot', 'Lot only'],
    ['apartment', 'Apartment or building'],
    ['commercial', 'Commercial'],
    ['farm', 'Farm or beach property'],
];

$residence = [
    'label' => 'Where do you live now?',
    'short' => 'Lives',
    'multi' => false,
    'options' => [['ph', 'In the Philippines'], ['abroad', 'Abroad (OFW or expat)'], ['foreign', "I'm a foreign national"]],
];

return [

    'contact_time' => [['morning', 'Morning'], ['afternoon', 'Afternoon'], ['evening', 'Evening']],

    /** [key, label, floor in pesos]. The floor feeds the lead grade. */
    'budget' => [
        ['under_1m', 'Under ₱1M', 0],
        ['1m_2m', '₱1M – 2M', 1000000],
        ['2m_3m', '₱2M – 3M', 2000000],
        ['3m_5m', '₱3M – 5M', 3000000],
        ['5m_8m', '₱5M – 8M', 5000000],
        ['8m_15m', '₱8M – 15M', 8000000],
        ['15m_30m', '₱15M – 30M', 15000000],
        ['30m_plus', '₱30M+', 30000000],
    ],

    /** The grade thresholds in pesos. */
    'grade' => [
        'priority_min' => 3000000,
        'qualified_min' => 1000000,
        'labels' => [
            'priority' => ['label' => 'Priority', 'why' => '₱3M or more, buying within 6 months, funds in place, ready to go ahead'],
            'qualified' => ['label' => 'Qualified', 'why' => '₱1M or more, buying within a year, not only exploring'],
            'nurture' => ['label' => 'Nurture', 'why' => "Interested, but the budget, timing or funds aren't there yet"],
            'info' => ['label' => 'Information', 'why' => 'Only exploring for now'],
        ],
    ],

    'buyer' => [
        'steps' => [
            ['id' => 'details', 'title' => 'Your details', 'keys' => ['residence', 'contact_channel']],
            ['id' => 'profile', 'title' => 'Buying profile', 'keys' => ['buying_for', 'goal', 'buying_with', 'buy_timeline', 'move_in']],
            ['id' => 'preferences', 'title' => 'Preferences', 'keys' => ['property_types', 'bedrooms', 'completion', 'areas', 'must_haves']],
            ['id' => 'financials', 'title' => 'Financials', 'keys' => ['income_source', 'payment', 'loan_status', 'down_payment', 'readiness']],
        ],
        'required' => ['buying_for', 'buy_timeline', 'payment', 'readiness'],
        'questions' => [
            'residence' => $residence,
            'contact_channel' => [
                'label' => 'How should we reach you?',
                'short' => 'Reach by',
                'multi' => false,
                'options' => [['viber', 'Viber'], ['whatsapp', 'WhatsApp'], ['messenger', 'Messenger'], ['call_text', 'Call or text'], ['email', 'Email']],
            ],
            'buying_for' => [
                'label' => 'Who are you buying for?',
                'short' => 'Buying for',
                'multi' => false,
                'options' => [['myself', 'Myself'], ['family', 'My family'], ['investment', 'As an investment'], ['company', 'A company']],
            ],
            'goal' => [
                'label' => 'What matters most to you?',
                'short' => 'Goal',
                'multi' => false,
                'options' => [
                    ['rental_income', 'Rental income'],
                    ['appreciation', 'Long-term growth in value'],
                    ['both', 'Both income and growth'],
                    ['home', 'A home for me or my family'],
                    ['retirement', 'A retirement home'],
                    ['not_sure', 'Not sure yet'],
                ],
            ],
            'buying_with' => [
                'label' => 'Are you buying alone?',
                'short' => 'Buying',
                'multi' => false,
                'options' => [['alone', 'Just me'], ['partner', 'With my spouse or partner'], ['family', 'With family'], ['business', 'With business partners']],
            ],
            'buy_timeline' => [
                'label' => 'When do you plan to buy?',
                'short' => 'Plans to buy',
                'multi' => false,
                'options' => [['asap', 'As soon as possible'], ['1_3m', 'In 1–3 months'], ['3_6m', 'In 3–6 months'], ['6_12m', 'In 6–12 months'], ['exploring', 'Just exploring']],
            ],
            'move_in' => [
                'label' => 'When would you like to move in?',
                'short' => 'Move in',
                'multi' => false,
                'options' => [['now', 'Ready now'], ['1y', 'Within a year'], ['1_3y', 'In 1–3 years, pre-selling is fine'], ['not_moving', "Not moving in, it's an investment"]],
            ],
            'property_types' => [
                'label' => 'What type of property?',
                'short' => 'Property type',
                'multi' => true,
                'options' => $propertyTypes,
            ],
            'bedrooms' => [
                'label' => 'Bedrooms',
                'short' => 'Bedrooms',
                'multi' => true,
                'options' => [['studio', 'Studio'], ['1', '1'], ['2', '2'], ['3', '3'], ['4', '4'], ['5_plus', '5+']],
            ],
            'completion' => [
                'label' => 'Pre-selling or ready for occupancy?',
                'short' => 'Completion',
                'multi' => false,
                'options' => [['preselling', 'Pre-selling'], ['rfo', 'Ready for occupancy (RFO)'], ['either', 'Either is fine']],
            ],
            'areas' => [
                'label' => 'Areas you like',
                'short' => 'Areas',
                'multi' => true,
                'options' => [...$areas, ['open', 'Open to suggestions']],
            ],
            'must_haves' => [
                'label' => 'Must-haves',
                'short' => 'Must-haves',
                'multi' => true,
                'options' => [
                    ['school', 'Near schools'],
                    ['hospital', 'Near a hospital'],
                    ['mall', 'Near a mall'],
                    ['transport', 'Near public transport'],
                    ['flood_free', 'Flood-free area'],
                    ['gated', 'Gated and secure'],
                    ['parking', 'Parking'],
                    ['pets', 'Pet-friendly'],
                    ['amenities', 'Pool and amenities'],
                    ['furnished', 'Furnished'],
                    ['work', 'Near my workplace'],
                ],
            ],
            'income_source' => [
                'label' => 'Main source of income',
                'short' => 'Income',
                'multi' => false,
                'options' => [
                    ['employed', 'Employed (salary)'],
                    ['business', 'Business owner'],
                    ['self_employed', 'Self-employed / freelancer'],
                    ['ofw', 'OFW remittance'],
                    ['investments', 'Investments or rental income'],
                    ['retired', 'Retired / pension'],
                    ['other', 'Other'],
                ],
            ],
            'payment' => [
                'label' => 'How will you pay?',
                'short' => 'Payment',
                'multi' => false,
                'options' => [['cash', 'Cash'], ['bank_loan', 'Bank housing loan'], ['pagibig', 'Pag-IBIG loan'], ['in_house', 'In-house (developer) financing'], ['not_sure', 'Not sure yet']],
            ],
            'loan_status' => [
                'label' => 'Loan pre-approval',
                'short' => 'Loan',
                'multi' => false,
                'options' => [['approved', 'Pre-approved'], ['in_progress', 'In progress'], ['not_yet', 'Not yet']],
                'show_when' => ['key' => 'payment', 'in' => ['bank_loan', 'pagibig']],
            ],
            'down_payment' => [
                'label' => 'Down payment you have ready',
                'short' => 'Down payment',
                'multi' => false,
                'options' => [['5_10', '5–10%'], ['10_20', '10–20%'], ['20_30', '20–30%'], ['30_plus', 'More than 30%'], ['not_sure', 'Not sure yet']],
            ],
            'readiness' => [
                'label' => 'How ready are you to go ahead?',
                'short' => 'Readiness',
                'multi' => false,
                'options' => [
                    ['reserve', 'Ready to reserve'],
                    ['serious', 'Seriously considering'],
                    ['family', 'Need to talk to family or partners'],
                    ['info', 'Need more information first'],
                    ['exploring', 'Only exploring'],
                ],
            ],
        ],
        /** Free text beside the choices: key => ['max' => chars, 'digits' => bool, 'list' => max items]. */
        'texts' => [
            'nationality' => ['max' => 60],
            'areas_cities' => ['max' => 80, 'list' => 8],
            'profession' => ['max' => 80],
            'position' => ['max' => 120],
        ],
    ],

    'seller' => [
        'steps' => [
            ['id' => 'details', 'title' => 'Your details', 'keys' => ['relation', 'residence']],
            ['id' => 'property', 'title' => 'The property', 'keys' => ['property_type', 'area', 'bedrooms', 'bathrooms', 'furnishing', 'features']],
            ['id' => 'status', 'title' => 'Status & price', 'keys' => ['completion', 'ownership', 'paid_percent', 'turnover', 'occupancy', 'tenancy_ends', 'valuation', 'title']],
            ['id' => 'plans', 'title' => 'Your plans', 'keys' => ['sell_timeline', 'reason', 'listed', 'also_rent']],
        ],
        'required' => ['property_type', 'completion', 'sell_timeline'],
        'questions' => [
            'relation' => [
                'label' => 'Are you the owner?',
                'short' => 'Owner',
                'multi' => false,
                'options' => [['owner', "Yes, I'm the owner"], ['co_owner', 'I co-own it'], ['representative', "I'm acting for the owner"]],
            ],
            'residence' => $residence,
            'property_type' => [
                'label' => 'What type of property is it?',
                'short' => 'Property type',
                'multi' => false,
                'options' => $propertyTypes,
            ],
            'area' => [
                'label' => 'Where is it?',
                'short' => 'Area',
                'multi' => false,
                'options' => $areas,
            ],
            'bedrooms' => [
                'label' => 'Bedrooms',
                'short' => 'Bedrooms',
                'multi' => false,
                'options' => [['studio', 'Studio'], ['1', '1'], ['2', '2'], ['3', '3'], ['4', '4'], ['5_plus', '5+']],
                'show_when' => ['key' => 'property_type', 'not_in' => ['lot', 'commercial']],
            ],
            'bathrooms' => [
                'label' => 'Bathrooms',
                'short' => 'Bathrooms',
                'multi' => false,
                'options' => [['1', '1'], ['2', '2'], ['3', '3'], ['4', '4'], ['5_plus', '5+']],
                'show_when' => ['key' => 'property_type', 'not_in' => ['lot']],
            ],
            'furnishing' => [
                'label' => 'Is it furnished?',
                'short' => 'Furnishing',
                'multi' => false,
                'options' => [['furnished', 'Furnished'], ['partly', 'Partly furnished'], ['unfurnished', 'Unfurnished']],
                'show_when' => ['key' => 'property_type', 'not_in' => ['lot']],
            ],
            'features' => [
                'label' => 'Highlights',
                'short' => 'Highlights',
                'multi' => true,
                'options' => [
                    ['corner_lot', 'Corner lot'],
                    ['gated', 'Gated subdivision'],
                    ['main_road', 'Near the main road'],
                    ['pool', 'Pool and amenities'],
                    ['balcony', 'Balcony'],
                    ['garden', 'Garden'],
                    ['garage', 'Garage or parking'],
                    ['maid_room', "Maid's room"],
                    ['high_floor', 'High floor'],
                    ['view', 'Sea or mountain view'],
                    ['renovated', 'Newly renovated'],
                ],
            ],
            'completion' => [
                'label' => 'Is it built or still pre-selling?',
                'short' => 'Status',
                'multi' => false,
                'options' => [['ready', 'Built and ready'], ['preselling', 'Pre-selling, not yet turned over']],
            ],
            'ownership' => [
                'label' => 'Payment status',
                'short' => 'Paid',
                'multi' => false,
                'options' => [['fully_paid', 'Fully paid'], ['bank_loan', 'Still paying a bank or Pag-IBIG loan'], ['developer', 'Still paying the developer']],
            ],
            'paid_percent' => [
                'label' => 'How much of the price have you paid?',
                'short' => 'Paid so far',
                'multi' => false,
                'options' => [['under_30', 'Under 30%'], ['30_50', '30–50%'], ['50_80', '50–80%'], ['80_plus', '80% or more']],
                'show_when' => ['key' => 'ownership', 'in' => ['bank_loan', 'developer']],
            ],
            'turnover' => [
                'label' => 'Expected turnover',
                'short' => 'Turnover',
                'multi' => false,
                'options' => [['within_1y', 'Within a year'], ['1_2y', 'In 1–2 years'], ['2y_plus', 'In more than 2 years'], ['not_sure', 'Not sure']],
                'show_when' => ['key' => 'completion', 'in' => ['preselling']],
            ],
            'occupancy' => [
                'label' => 'Who lives there now?',
                'short' => 'Occupancy',
                'multi' => false,
                'options' => [['vacant', "It's vacant"], ['owner', 'I live in it'], ['tenanted', "It's rented out"]],
                'show_when' => ['key' => 'completion', 'in' => ['ready']],
            ],
            'tenancy_ends' => [
                'label' => 'When does the lease end?',
                'short' => 'Lease ends',
                'multi' => false,
                'options' => [['under_3m', 'Within 3 months'], ['3_6m', 'In 3–6 months'], ['6_12m', 'In 6–12 months'], ['12m_plus', 'In more than a year']],
                'show_when' => ['key' => 'occupancy', 'in' => ['tenanted']],
            ],
            'valuation' => [
                'label' => 'Would you like a free valuation?',
                'short' => 'Valuation',
                'multi' => false,
                'options' => [['yes', 'Yes, please'], ['no', 'No, I have a price in mind']],
            ],
            'title' => [
                'label' => 'Title status',
                'short' => 'Title',
                'multi' => false,
                'options' => [
                    ['clean', 'Clean title (TCT or CCT) in my name'],
                    ['in_process', 'Title in process'],
                    ['developer', "Still under the developer's name"],
                    ['tax_dec', 'Tax declaration only'],
                    ['mother', 'Mother title'],
                    ['not_sure', 'Not sure'],
                ],
            ],
            'sell_timeline' => [
                'label' => 'When do you want to sell?',
                'short' => 'Wants to sell',
                'multi' => false,
                'options' => [['asap', 'As soon as possible'], ['1_3m', 'In 1–3 months'], ['3_6m', 'In 3–6 months'], ['exploring', 'Just checking the price']],
            ],
            'reason' => [
                'label' => 'Why are you selling?',
                'short' => 'Reason',
                'multi' => false,
                'options' => [
                    ['upgrading', 'Upgrading'],
                    ['relocating', 'Relocating'],
                    ['migrating', 'Migrating abroad'],
                    ['cash', 'Need the cash'],
                    ['investment', 'Taking profit on an investment'],
                    ['other', 'Something else'],
                ],
            ],
            'listed' => [
                'label' => 'Is it listed with other agents?',
                'short' => 'Listed elsewhere',
                'multi' => false,
                'options' => [['no', 'No'], ['yes', 'Yes']],
            ],
            'also_rent' => [
                'label' => 'Would you consider renting it out instead?',
                'short' => 'Would rent',
                'multi' => false,
                'options' => [['yes', 'Yes'], ['maybe', 'Maybe'], ['no', 'No']],
            ],
        ],
        'texts' => [
            'nationality' => ['max' => 60],
            'city' => ['max' => 80],
            'building' => ['max' => 120],
            'lot_area_sqm' => ['max' => 7, 'digits' => true],
            'floor_area_sqm' => ['max' => 7, 'digits' => true],
            'asking_price' => ['max' => 12, 'digits' => true],
        ],
    ],
];
