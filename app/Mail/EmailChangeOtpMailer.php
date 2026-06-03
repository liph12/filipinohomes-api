<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailChangeOtpMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public $email;
    public $verification;
    public $name;

    public function __construct($email, $verification, $name)
    {
        $this->email = $email;
        $this->verification = $verification;
        $this->name = $name;
        $this->tagFhMailerHeader();
    }

    public function build()
    {
        return $this->to($this->email)
            ->from(env('MAIL_FROM'), 'FH Support Team')
            ->subject('Confirm your new Filipino Homes email')
            ->markdown('mail.email-change-otp-mailer');
    }
}
