<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MessageNotificationMailer extends Mailable
{
    use Queueable, SerializesModels;

    public $receiverEmail;
    public $receiverName;
    public $senderEmail;
    public $senderName;
    public $message;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($sender, $receiver, $message)
    {
        $this->receiverEmail = $receiver->email;
        $this->receiverName = $receiver->name;
        $this->senderEmail = $sender->email;
        $this->senderName = $sender->name;
        $this->message = $message;
    }

    /**
     * Build the message. 
     *
     * @return $this
     */
    public function build()
    {
        $mail = $this->to('libresphilip14@gmail.com')->from(env('MAIL_FROM'), 'FH Support Team')
        ->subject('Filipino Homes - New message received')
        ->markdown('emails.message-notification');

        return $mail;
    }
}
