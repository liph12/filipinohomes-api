{{-- Get In Touch (homepage) / Maintenance page / other website forms → admins (BCC behind info@).
     Reply-To is the client, so admins can answer straight from their email app. --}}
@php
    // Keep keys in sync with the frontend POST payloads (GetInTouchSection.tsx →
    // 'home_get_in_touch'; maintenance page → 'maintenance_page').
    $sources = [
        'home_get_in_touch' => ['label' => 'Get In Touch', 'heading' => 'New Get In Touch message', 'color' => 'blue', 'from' => 'the homepage Get In Touch form'],
        'maintenance_page' => ['label' => 'Maintenance', 'heading' => 'New message from the maintenance page', 'color' => 'amber', 'from' => 'the maintenance page'],
        'contact_page' => ['label' => 'Contact Us', 'heading' => 'New Contact Us message', 'color' => 'teal', 'from' => 'the Contact Us page'],
    ];
    $cfg = $sources[$source ?? ''] ?? [
        'label' => !empty($source) ? ucwords(str_replace('_', ' ', $source)) : 'Website Inquiry',
        'heading' => 'New website inquiry', 'color' => 'blue', 'from' => 'Filipino Homes',
    ];
    $site = rtrim((string) (env('FRONTEND_URL') ?: 'https://filipinohomes.com'), '/');
@endphp
<x-email.layout footer="You're receiving this because you're an administrator at Filipino Homes.">
    <x-email.heading :color="$cfg['color']">{{ $cfg['heading'] }}</x-email.heading>

    <x-email.greeting name="Administrator">
        A new <strong style="color:#162033;">{{ $cfg['label'] }}</strong> message was submitted through {{ $cfg['from'] }}. Details are below — please follow up with the client at your earliest convenience.
    </x-email.greeting>

    <x-email.message :message="$clientMessage" />
    <x-email.person label="From" :name="$clientName" :email="$clientEmail" :avatar="$clientAvatar ?? null" />

    <x-email.respond :url="$site.'/admin/contact-inbox'" button="Open Contact Inbox"
        :secondary-url="'mailto:'.$clientEmail" secondary-label="Email the client"
        title="Reply to the client by email, or follow up from the Contact Inbox."
        note="Replying to this email goes straight to the client." />
</x-email.layout>
