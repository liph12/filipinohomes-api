{{-- Contact Us page → admins (BCC behind info@). Reply-To is the client. --}}
@php $site = rtrim((string) (env('FRONTEND_URL') ?: 'https://filipinohomes.com'), '/'); @endphp
<x-email.layout footer="You're receiving this because you're an administrator at Filipino Homes.">
    <x-email.heading color="teal">New Contact Us message</x-email.heading>

    <x-email.greeting name="Administrator">
        A new message was submitted through the Contact Us page. Details are below — please follow up with the client at your earliest convenience.
    </x-email.greeting>

    <x-email.details :rows="['Inquiry Type' => $inquiryType ?? null, 'Subject' => $clientSubject ?? null]" />
    <x-email.message :message="$clientMessage" />
    <x-email.person label="From" :name="$clientName" :email="$clientEmail" :mobile="$clientPhone ?? null" :avatar="$clientAvatar ?? null" />

    <x-email.respond :url="$site.'/admin/contact-inbox'" button="Open Contact Inbox"
        :secondary-url="'mailto:'.$clientEmail" secondary-label="Email the client"
        title="Reply to the client by email, or follow up from the Contact Inbox."
        note="Replying to this email goes straight to the client." />
</x-email.layout>
