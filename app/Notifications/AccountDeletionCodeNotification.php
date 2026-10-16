<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountDeletionCodeNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Canovia アカウント削除の確認コード')
            ->greeting('アカウント削除の確認')
            ->line('アカウント削除がリクエストされました。操作した覚えがない場合は、このメールを無視してください。')
            ->line('確認コード: '.$this->code)
            ->line('このコードの有効期限は10分です。他人に教えないでください。');
    }
}
