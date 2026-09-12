<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderCompleted extends Notification
{
    use Queueable;

    public function __construct(public Order $order)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("Order {$this->order->order_number} confirmed")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your order {$this->order->order_number} has been processed.")
            ->line("Total: {$this->order->total_amount}");

        foreach ($this->order->items as $item) {
            $mail->line("{$item->quantity} x {$item->product->name} ({$item->subtotal})");
        }

        return $mail;
    }
}
