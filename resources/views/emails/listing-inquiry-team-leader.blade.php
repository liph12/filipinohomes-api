{{-- Listing inquiry, TEAM LEADER copy: one of the leader's agents got an inquiry — pending review.
     Goes to exactly one BCC recipient (the leader), so it's safe to greet them by name. --}}
@php
    $resolvedTeamName = !empty($teamName) ? $teamName : '(unassigned)';
    $owner = $listing ?? [];
@endphp
<x-email.layout :footer="'You\'re receiving this because you\'re the team leader of '.$resolvedTeamName.' at Filipino Homes.'">
    <x-email.heading color="purple">New inquiry for your team</x-email.heading>

    <x-email.greeting :name="ucwords(strtolower($receiverName))">
        As the team leader of <strong style="color:#162033;">{{ $resolvedTeamName }}</strong>, you're being notified because one of your agents just received a new property inquiry. Please review it alongside the admin team and accept or reject it from the inbox.
    </x-email.greeting>

    <x-email.callout tone="purple" title="Your Team">{{ $resolvedTeamName }}</x-email.callout>

    <x-email.listing-card :listing="$listing" label="Listing" />
    <x-email.message :message="$message" />
    <x-email.person label="From" :name="$senderName" :email="$senderEmail" :mobile="$senderMobile" :whatsapp="$senderWhatsapp" :avatar="$senderAvatar" />

    @if (!empty($owner['owner_name']) || !empty($owner['owner_email']) || !empty($owner['owner_mobile']) || !empty($owner['owner_whatsapp']))
        <x-email.person label="Listing Owner (your team member)" :name="$owner['owner_name'] ?? null" :email="$owner['owner_email'] ?? null" :mobile="$owner['owner_mobile'] ?? null" :whatsapp="$owner['owner_whatsapp'] ?? null" :avatar="$owner['owner_avatar'] ?? null" avatar-bg="6d28d9" />
    @endif

    <x-email.respond :url="$frontendUrl.'/inbox/'.$slug" button="Review Inquiry" :listing-url="$listing['public_url'] ?? null"
        title="To review this inquiry, open it in your Filipino Homes account." :note="null" />
</x-email.layout>
