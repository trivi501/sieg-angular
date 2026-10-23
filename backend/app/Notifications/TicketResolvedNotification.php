<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TicketResolvedNotification extends Notification
{
    use Queueable;

    public function __construct(public SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusText = $this->ticket->status === 'cerrado' ? 'cerrado' : 'resuelto';

        return (new MailMessage)
            ->subject("Ticket {$statusText} #{$this->ticket->id}")
            ->greeting("Tu ticket ha sido {$statusText}")
            ->line("Asunto: {$this->ticket->title}")
            ->line("Estado: {$statusText}")
            ->action('Ver ticket', config('app.frontend_url')."/tickets/{$this->ticket->id}");
    }

    public function toArray(object $notifiable): array
    {
        $statusText = $this->ticket->status === 'cerrado' ? 'cerrado' : 'resuelto';
        return [
            'ticket_id' => $this->ticket->id,
            'title' => $this->ticket->title,
            'message' => "Tu ticket ha sido {$statusText}: {$this->ticket->title}",
        ];
    }
}
