<?php

use Illuminate\Support\Facades\Route;
use App\Mail\AdminActivityReportMailer;
use App\Mail\StaffBirthdaysMailer;
use App\Mail\AtsStatusUpdatedMailer;
use App\Mail\AtsExpiryMailer;
use App\Mail\ContactUsMailer;
use App\Mail\InquiryMailer;
use App\Mail\ListingFlaggedMailer;
use App\Mail\ListingVerifiedMailer;
use App\Mail\LoginOtpMailer;
use App\Mail\MessageNotificationMailer;
use App\Natcon\Mail\PhotoInviteMailer;

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
            'amenities'         => false,
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

        // ATS status email — one closure rendered per status so each
        // color-coded variant (approved/pending/expired/rejected) can be previewed.
        $atsMailer = fn (string $status) => new AtsStatusUpdatedMailer(
            agentName:     $shared['agentName'],
            listingTitle:  $shared['listingTitle'],
            listingCode:   $shared['listingCode'],
            atsStatus:     $status,
            atsRemarks:    'Please re-upload a clearer, unexpired copy of the signed Authority to Sell.',
            atsExpiration: 'December 25, 2026',
            listingUrl:    $shared['listingUrl'],
            featuredPhoto: 'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=400',
        );

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
            'ats-approved' => $atsMailer('Approved'),
            'ats-pending'  => $atsMailer('Pending'),
            'ats-expired'  => $atsMailer('Expired'),
            'ats-rejected' => $atsMailer('Rejected'),
            'ats-expiring-soon', 'ats-expiry-expired' => new AtsExpiryMailer(
                mode:          $type === 'ats-expiry-expired' ? 'expired' : 'soon',
                agentName:     $shared['agentName'],
                listingTitle:  $shared['listingTitle'],
                listingCode:   $shared['listingCode'],
                atsExpiration: 'December 25, 2026',
                atsRemarks:    'Renew your Authority to Sell before the expiration date to avoid any listing downtime.',
                listingUrl:    $shared['listingUrl'],
                featuredPhoto: 'https://images.unsplash.com/photo-1568605114967-8130f3a36994?w=400',
            ),
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
            // Three per-role previews for the redesigned listing-inquiry
            // flow. Each instantiates the same Mailable with a different
            // perspective so QA can compare the ribbons / greetings /
            // footers side-by-side without sending real mail.
            'inquiry-admin' => new MessageNotificationMailer(
                sender:           $sender,
                receiver:         $makeUser(['email' => 'info@filipinohomes.com', 'name' => 'Admin']),
                message:          "Hi! I'm interested in this listing. Could we schedule a viewing this weekend?",
                slug:             'demo-listing-123',
                roleName:         'admin',
                listing:          $listing,
                agentUserId:      null,
                showListingOwner: true,
                perspective:      'admin',
                teamName:         'Cebu IT Park Specialists',
                teamId:           7,
            ),
            // Same admin blade but with no team — exercises the loud red
            // "Action Needed" callout for the unassigned-agent case.
            'inquiry-admin-unassigned' => new MessageNotificationMailer(
                sender:           $sender,
                receiver:         $makeUser(['email' => 'info@filipinohomes.com', 'name' => 'Admin']),
                message:          "Hi! I'm interested in this listing. Could we schedule a viewing this weekend?",
                slug:             'demo-listing-123',
                roleName:         'admin',
                listing:          $listing,
                agentUserId:      null,
                showListingOwner: true,
                perspective:      'admin',
                teamName:         null,
                teamId:           null,
            ),
            'inquiry-team-leader' => new MessageNotificationMailer(
                sender:           $sender,
                receiver:         $makeUser(['email' => 'leader@example.com', 'name' => 'Ana Reyes']),
                message:          "Hi! I'm interested in this listing. Could we schedule a viewing this weekend?",
                slug:             'demo-listing-123',
                roleName:         'team_leader',
                listing:          $listing,
                agentUserId:      null,
                showListingOwner: true,
                perspective:      'team_leader',
                teamName:         'Cebu IT Park Specialists',
                teamId:           7,
            ),
            'inquiry-agent' => new MessageNotificationMailer(
                sender:           $sender,
                receiver:         $receiver,
                message:          "Hi! I'm interested in this listing. Could we schedule a viewing this weekend?",
                slug:             'demo-listing-123',
                roleName:         'agent',
                listing:          $listing,
                agentUserId:      null,
                showListingOwner: false,
                perspective:      'agent',
            ),
            'otp'      => new LoginOtpMailer(
                'juan.cruz@example.com',
                '482913',
                'Juan Dela Cruz',
            ),

            // NATCON 2026 photo-confirmation campaign. Four variants because the
            // template branches on two independent things — mode and whether we
            // hold a photo — and all four go to real awardees.
            'natcon-invite',
            'natcon-invite-no-photo',
            'natcon-reminder',
            'natcon-reminder-last' => (function () use ($type) {
                $photos = [
                    'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/natcon-invitation/photos/IV1R1757541872.png',
                    'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/natcon-invitation/photos/1EVN1757541872.png',
                ];

                $isReminder = str_starts_with($type, 'natcon-reminder');
                $days       = match ($type) {
                    'natcon-reminder'      => 4,
                    'natcon-reminder-last' => 0,
                    default                => 12,
                };

                return new PhotoInviteMailer(
                    mode:            $isReminder ? 'reminder' : 'invite',
                    recipientName:   'Eutequio Rallos Jr.',
                    team:            'LR Alliance',
                    photos:          $type === 'natcon-invite-no-photo' ? [] : $photos,
                    retainUrl:       'https://filipinohomes.com/natcon/update-profile?email=demo%40example.com&intent=retain&t=demo-token',
                    changeUrl:       'https://filipinohomes.com/natcon/update-profile?email=demo%40example.com&intent=change&t=demo-token',
                    deadlineLabel:   'August 24, 2026',
                    deadlineDay:     '24th',
                    daysRemaining:   $days,
                    eventName:       'NATCON 2026',
                    eventDates:      'October 18–19, 2026',
                    eventVenue:      'JPark Island Resort & Waterpark Mactan, Cebu',
                    bannerUrl:       'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/filipinohomes-new/natcon-2026/email/banner-1200.jpg',
                    reminderIndex:   $isReminder ? 1 : null,
                );
            })(),

            // Boss activity digest — TODAY's live data only, nothing sampled.
            'boss-report' => (function () {
                $today = now()->toDateString();

                return new AdminActivityReportMailer(
                    report: app(\App\Services\Reports\AdminActivityReportService::class)->build($today, $today),
                    periodLabel: now()->format('M j, Y'),
                    recipientName: 'Boss',
                );
            })(),

            // Staff birthdays digest — live local data.
            'birthdays' => new StaffBirthdaysMailer(
                birthdays: app(\App\Services\Reports\StaffBirthdaysService::class)->build(now()->toDateString()),
                dateLabel: now()->format('M j, Y'),
                recipientName: 'Boss',
            ),

            default    => abort(404, 'Unknown email type. Try one of: boss-report, birthdays, flagged, verified, ats-approved, ats-pending, ats-expired, ats-rejected, ats-expiring-soon, ats-expiry-expired, inquiry, contact-us, notification, inquiry-admin, inquiry-admin-unassigned, inquiry-team-leader, inquiry-agent, otp, natcon-invite, natcon-invite-no-photo, natcon-reminder, natcon-reminder-last'),
        };
    });
}
