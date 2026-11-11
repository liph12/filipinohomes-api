{{-- Chat reminder ("New message received") to the other person in a conversation.
     Replies go through the website (Reply-To is a no-reply address). --}}
@php $owner = $listing ?? []; @endphp
<x-email.layout :footer="!empty($showListingOwner) ? 'You\'re receiving this because you\'re an admin or team leader at Filipino Homes.' : 'You\'re receiving this because you\'re part of this conversation on Filipino Homes.'">
    <x-email.heading color="blue">New message received</x-email.heading>

    <x-email.greeting :name="ucwords(strtolower($greetingName))">
        A new message has been submitted through Filipino Homes. Please review below and respond at your earliest convenience.
    </x-email.greeting>

    <x-email.listing-card :listing="$listing" label="Listing" />
    <x-email.message :message="$message" />
    <x-email.person label="From" :name="$senderName" :email="$senderEmail" :mobile="$senderMobile" :whatsapp="$senderWhatsapp" :avatar="$senderAvatar" />

    @if (!empty($showListingOwner) && (!empty($owner['owner_name']) || !empty($owner['owner_email']) || !empty($owner['owner_mobile']) || !empty($owner['owner_whatsapp'])))
        <x-email.person label="Listing Owner" :name="$owner['owner_name'] ?? null" :email="$owner['owner_email'] ?? null" :mobile="$owner['owner_mobile'] ?? null" :whatsapp="$owner['owner_whatsapp'] ?? null" :avatar="$owner['owner_avatar'] ?? null" avatar-bg="0f766e" />
    @endif

    <x-email.respond :url="$frontendUrl.'/inbox/'.$slug" :listing-url="$listing['public_url'] ?? null"
        title="To reply, open the conversation in your Filipino Homes account." />
</x-email.layout>
