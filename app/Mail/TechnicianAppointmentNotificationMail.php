<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TechnicianAppointmentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public User $recipient,
        public string $eventType,
    ) {
        $this->appointment->loadMissing(['service:id,type,name', 'technician:id,first_name,last_name,email', 'creator:id,first_name,last_name']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectForEvent());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.appointments.technician-notification',
            with: [
                'eventLabel' => $this->eventLabel(),
                'intro' => $this->intro(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    public function eventLabel(): string
    {
        return match ($this->eventType) {
            'created' => 'Nouveau rendez-vous',
            'details_updated' => 'Rendez-vous modifié',
            'reassigned_to' => 'Rendez-vous ajouté à votre planning',
            'reassigned_from' => 'Rendez-vous retiré de votre planning',
            'cancelled' => 'Rendez-vous annulé',
            'restored' => 'Rendez-vous réactivé',
            'comment_updated' => 'Commentaire mis à jour',
            default => 'Mise à jour de rendez-vous',
        };
    }

    public function intro(): string
    {
        return match ($this->eventType) {
            'created' => 'Un nouveau rendez-vous vient d’être placé dans votre planning.',
            'details_updated' => 'Les informations de ce rendez-vous ont été modifiées.',
            'reassigned_to' => 'Ce rendez-vous vient de vous être réaffecté.',
            'reassigned_from' => 'Ce rendez-vous a été retiré de votre planning.',
            'cancelled' => 'Ce rendez-vous a été désactivé dans le planning.',
            'restored' => 'Ce rendez-vous a été réactivé dans le planning.',
            'comment_updated' => 'Le commentaire de ce rendez-vous a été mis à jour.',
            default => 'Ce rendez-vous a été mis à jour.',
        };
    }

    private function subjectForEvent(): string
    {
        return $this->eventLabel().' - '.$this->appointmentDateLabel();
    }

    private function appointmentDateLabel(): string
    {
        return $this->appointment->starts_at
            ? $this->appointment->starts_at->format('d/m/Y H:i')
            : 'date à confirmer';
    }
}
