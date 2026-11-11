{{-- Listing inquiry, ADMIN copy: sent (BCC behind info@) when a client submits — pending review. --}}
@php
    $hasTeam = !empty($teamName);
    $owner = $listing ?? [];
@endphp
<x-email.layout footer="You're receiving this because you're an admin at Filipino Homes.">
    <x-email.heading color="amber">New inquiry pending review</x-email.heading>

    <x-email.greeting name="Admin">
        A new property inquiry has been submitted on Filipino Homes and is awaiting review. Please look it over and accept or reject it from the admin inbox.
    </x-email.greeting>

    {{-- Who's responsible: the agent's team, or a warning when the agent has no team
         (no team leader was notified, so it can't route to one). --}}
    @if ($hasTeam)
        <x-email.callout tone="amber" title="Agent Team">{{ $teamName }}</x-email.callout>
    @else
        <x-email.callout tone="red" title="⚠️ Action needed — agent not on a team">
            This listing's agent isn't assigned to any team yet. No team leader was notified about this inquiry. Consider assigning the agent to a team in the admin panel before accepting, so future inquiries route correctly.
        </x-email.callout>
    @endif

    <x-email.listing-card :listing="$listing" label="Listing" />
    <x-email.message :message="$message" />
    <x-email.person label="From" :name="$senderName" :email="$senderEmail" :mobile="$senderMobile" :whatsapp="$senderWhatsapp" :avatar="$senderAvatar" />

    @if (!empty($owner['owner_name']) || !empty($owner['owner_email']) || !empty($owner['owner_mobile']) || !empty($owner['owner_whatsapp']))
        <x-email.person label="Listing Owner" :name="$owner['owner_name'] ?? null" :email="$owner['owner_email'] ?? null" :mobile="$owner['owner_mobile'] ?? null" :whatsapp="$owner['owner_whatsapp'] ?? null" :avatar="$owner['owner_avatar'] ?? null" avatar-bg="0f766e" />
    @endif

    <x-email.respond :url="$frontendUrl.'/inbox/'.$slug" button="Review Inquiry" :listing-url="$listing['public_url'] ?? null"
        title="To review this inquiry, open it in the admin inbox." :note="null" />
</x-email.layout>
