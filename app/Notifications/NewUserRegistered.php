<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewUserRegistered extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        protected User $user
    )
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $loginHistory = $this->user->lastLogin();

        return (new MailMessage)
            ->subject('New User Registration')
            ->greeting('Hello Super Admin!')
            ->line('A new user has registered on the platform.')
            ->line('User Details:')
            ->line('Name: ' . $this->user->name)
            ->line('Email: ' . $this->user->email)
            ->line('Registered at: ' . $this->user->created_at->format('Y-m-d H:i:s'))
            ->when($loginHistory, function (MailMessage $message) use ($loginHistory) {
                return $message
                    ->line('First login from IP: ' . $loginHistory->ip_address)
                    ->line('Browser: ' . $loginHistory->user_agent);
            })
            ->action('View User', url('/users'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
