<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactFormNotification extends Notification 
{
    // use Queueable;

    public function __construct(public ContactMessage $contactMessage)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail',];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Contact Form Message: ' . $this->contactMessage->subject)
            ->greeting('Hello Admin!')
            ->line('You have received a new contact form message.')
            ->line('**From:** ' . $this->contactMessage->name)
            ->line('**Email:** ' . $this->contactMessage->email)
            ->line('**Subject:** ' . $this->contactMessage->subject)
            ->line('**Message:**')
            ->line($this->contactMessage->message)
            ->action('View Message', url('/admin/contact-messages/' . $this->contactMessage->id))
            ->line('Thank you for using our application!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message_id' => $this->contactMessage->id,
            'name' => $this->contactMessage->name,
            'email' => $this->contactMessage->email,
            'subject' => $this->contactMessage->subject,
            'message' => $this->contactMessage->message,
            'type' => 'contact_form'
        ];
    }
}