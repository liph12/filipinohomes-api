<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class MessageNotificationMailer extends Mailable
{
    use Queueable, SerializesModels;

    public $receiverEmail;
    public $receiverName;
    public $senderEmail;
    public $senderName;
    public $message;
    public $slug;
    public $roleName;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($sender, $receiver, $message, $slug, $roleName = 'agent')
    {
        $this->receiverEmail = $receiver->email;
        $this->receiverName = $receiver->name;
        $this->senderEmail = $sender->email;
        $this->senderName = $sender->name;
        $this->message = $message;
        $this->slug = $slug;
        $this->roleName = $roleName;
    }

    /**
     * Build the message. 
     *
     * @return $this
     */
    public function build()
    {
        $adminEmails = User::where('role_id', 1)->pluck('email');

        $mail = $this->to($this->receiverEmail)->from(env('MAIL_FROM'), 'FH Support Team')
        ->subject('Filipino Homes - New message received')
        ->bcc($adminEmails)
        ->markdown('emails.message-notification');

        return $mail;
    }
}
