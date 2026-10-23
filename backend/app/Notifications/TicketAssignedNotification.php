<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TicketAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ticket asignado #{$this->ticket->id}")
            ->greeting("Te han asignado un ticket")
            ->line("Asunto: {$this->ticket->title}")
            ->line("Descripción: {$this->ticket->description}")
            ->line("Prioridad: {$this->ticket->priority}")
            ->action('Ver ticket', config('app.frontend_url')."/tickets/{$this->ticket->id}");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'title' => $this->ticket->title,
            'message' => "Te han asignado el ticket: {$this->ticket->title}",
        ];
    }
}
