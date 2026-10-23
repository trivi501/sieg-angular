<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TicketCommentedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public SupportTicket $ticket,
        public string $comment,
        public string $commenterName
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Nuevo comentario en ticket #{$this->ticket->id}")
            ->greeting("{$this->commenterName} comentó en un ticket")
            ->line("Ticket: {$this->ticket->title}")
            ->line("Comentario: {$this->comment}")
            ->action('Ver ticket', config('app.frontend_url')."/tickets/{$this->ticket->id}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'title' => $this->ticket->title,
            'message' => "{$this->commenterName} comentó en el ticket: {$this->ticket->title}",
            'comment' => $this->comment,
        ];
    }
}
