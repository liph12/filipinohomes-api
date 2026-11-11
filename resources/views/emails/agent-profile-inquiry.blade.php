{{-- "Message Me" from an agent's profile / Website Builder page — straight to the agent, no review step. --}}
@php $channel = $channelLabel ?? 'agent profile'; @endphp
<x-email.layout :footer="'You\'re receiving this because a visitor messaged you through your Filipino Homes '.$channel.'.'">
    <x-email.heading color="teal">New inquiry from your {{ $channel }}</x-email.heading>

    <x-email.greeting :name="ucwords(strtolower($greetingName))">
        A visitor sent you a message from your Filipino Homes {{ $channel }}. Reply at your earliest convenience to keep the conversation moving.
    </x-email.greeting>

    <x-email.message :message="$message" />
    <x-email.person label="From" :name="$senderName" :email="$senderEmail" :mobile="$senderMobile" :whatsapp="$senderWhatsapp" :avatar="$senderAvatar" />

    {{-- Replies go through the website (Reply-To is a no-reply address). --}}
    <x-email.respond :url="$frontendUrl.'/inbox/'.$slug" />
</x-email.layout>
