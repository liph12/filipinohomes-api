<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginOtpMailer extends Mailable
{
    use Queueable, SerializesModels;
    public $email;
    public $verification;
    public $name;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($email, $verification, $name)
    {
        $this->email = $email;
        $this->verification = $verification;
        $this->name = $name;
    }

    /**
     * Build the message. 
     *
     * @return $this
     */
    public function build()
    {
        $mail = $this->to($this->email)->from(env('info@filipinohomes.com'), 'FH Support Team')->subject('FH Login OTP')->markdown('mail.login-otp-mailer');

        return $mail;
    }
}
