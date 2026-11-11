{{-- Listing inquiry, AGENT's copy: sent (BCC behind info@) once the inquiry is approved and assigned.
     Built from the shared email components (resources/views/components/email). --}}
<x-email.layout footer="You're receiving this because you're the listing owner.">
    <x-email.heading color="green">New inquiry assigned to you</x-email.heading>

    <x-email.greeting :name="ucwords(strtolower($greetingName))">
        An inquiry on your listing has been reviewed by the Filipino Homes team and is now assigned to you. Please respond at your earliest convenience to keep the lead warm.
    </x-email.greeting>

    <x-email.listing-card :listing="$listing" label="Your Listing" />
    <x-email.message :message="$message" />
    <x-email.person label="From" :name="$senderName" :email="$senderEmail" :mobile="$senderMobile" :whatsapp="$senderWhatsapp" :avatar="$senderAvatar" />

    {{-- Listing-owner card omitted: the agent IS the owner. Replies go through the website
         (Reply-To is a no-reply address). --}}
    <x-email.respond :url="$frontendUrl.'/inbox/'.$slug" :listing-url="$listing['public_url'] ?? null" />
</x-email.layout>
