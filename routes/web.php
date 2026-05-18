<?php

use Illuminate\Support\Facades\Route;
use App\Mail\ContactUsMailer;
use App\Mail\InquiryMailer;
use App\Mail\ListingFlaggedMailer;
use App\Mail\ListingVerifiedMailer;
use App\Mail\LoginOtpMailer;
use App\Mail\MessageNotificationMailer;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Email previews (dev only)
|--------------------------------------------------------------------------
| Visit /preview/email to pick a template, or hit /preview/email/{type}
| directly. Both routes render the Mailable's view with dummy data so you
| can iterate on the HTML without sending real mail. Wrapped in app.debug
| so this never leaks in production.
*/
if (config('app.debug')) {
    Route::get('/preview/email', function () {
        return view('email-preview-index');
    });

    Route::get('/preview/email/{type}', function (string $type) {
        $checklist = [
            'agent_verified'    => true,
            'ats_correct'       => true,
            'specs_correct'     => false,
            'price_realistic'   => true,
            'location_accurate' => true,
            'nearby_facilities' => false,
            'photos'            => false,
            'title_seo'         => false,
            'description'       => true,
        ];

        $shared = [
            'agentName'      => 'Juan Dela Cruz',
            'listingTitle'   => 'For Sale: 3BR Condo in Centro, Mandaue City — 65 sqm with Balcony',
            'listingCode'    => 'FH-DEMO-00123',
            'auditNotes'     => "Photos need to be re-uploaded in higher resolution.\nTitle should mention the project name (Mandani Bay).",
            'auditChecklist' => $checklist,
            'listingUrl'     => 'https://filipinohomes.com/agent/create-listing?edit=123',
        ];

        // Dummy "user" objects for MessageNotificationMailer (which reads
        // ->email / ->name / ->mobile_no / ->agent->whats_app_no off the
        // sender + receiver). stdClass is enough for a preview render.
        $makeUser = function (array $a) {
            $u = new stdClass();
            foreach ($a as $k => $v) $u->$k = $v;
            return $u;
        };
        $sender   = $makeUser([
            'email'     => 'maria.santos@example.com',
            'name'      => 'Maria Santos',
            'mobile_no' => '+63 917 555 0123',
            'agent'     => $makeUser(['whats_app_no' => '+63 917 555 0123']),
        ]);
        $receiver = $makeUser([
            'email' => 'juan.cruz@example.com',
            'name'  => 'Juan Dela Cruz',
        ]);
        $listing  = [
            'name'           => 'For Sale: 3BR Condo in Centro, Mandaue City',
            'price'          => 'PHP 5,500,000',
            'image'          => 'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=800',
            'location'       => 'Centro, Mandaue City, Cebu',
            'category'       => 'For Sale',
            'subtype'        => 'Condominium · 3 Bedrooms',
            'public_url'     => 'https://filipinohomes.com/listings/demo-listing',
            'owner_name'     => 'Juan Dela Cruz',
            'owner_mobile'   => '+63 917 555 0123',
            'owner_whatsapp' => '+63 917 555 0123',
            'owner_email'    => 'juan.cruz@example.com',
        ];

        return match ($type) {
            'flagged'  => new ListingFlaggedMailer(
                ...$shared,
                editedFields: [
                    ['label' => 'Price',      'original' => '5,000,000', 'current' => '5,500,000'],
                    ['label' => 'Floor Area', 'original' => '60 sqm',    'current' => '65 sqm'],
                ],
            ),
            'verified' => new ListingVerifiedMailer(...$shared),
            'inquiry'  => new InquiryMailer(
                clientName:    'Maria Santos',
                clientEmail:   'maria.santos@example.com',
                clientMessage: "Hi! I saw your 3BR condo listing in Mandaue and I'd like to schedule a viewing this weekend.\n\nIs Saturday afternoon possible? Also, is the unit still available for a March move-in?",
                source:        'home_get_in_touch',
            ),
            'contact-us' => new ContactUsMailer(
                clientName:    'Maria Santos',
                clientEmail:   'maria.santos@example.com',
                clientMessage: "I'm interested in scheduling a viewing for one of your Mandaue condo units this weekend. Could you let me know which units are still available for a March move-in, and what your earliest availability is for a Saturday afternoon tour?",
                clientPhone:   '+63 917 555 0123',
                inquiryType:   'Buying a Property',
                clientSubject: 'Mandaue 3BR Condo Viewing — Saturday',
            ),
            'notification' => new MessageNotificationMailer(
                $sender,
                $receiver,
                "Hi Juan, I just sent a new message about the 3BR Condo listing. Looking forward to your reply.",
                'demo-listing',
                'agent',
                $listing,
                null,
            ),
            'otp'      => new LoginOtpMailer(
                'juan.cruz@example.com',
                '482913',
                'Juan Dela Cruz',
            ),
            default    => abort(404, 'Unknown email type. Try one of: flagged, verified, inquiry, contact-us, notification, otp'),
        };
    });
}
