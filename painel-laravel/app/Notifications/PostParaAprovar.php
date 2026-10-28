<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email ao aprovador quando um post fica "À espera de aprovação".
 * Leva a imagem em anexo e a legenda, porque o painel só abre na rede interna.
 */
class PostParaAprovar extends Notification
{
    public function __construct(public Post $post) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->post;

        $mail = (new MailMessage)
            ->subject('Post para aprovar: '.$post->title)
            ->greeting('Olá '.$notifiable->name.',')
            ->line('Há um post novo à espera da sua aprovação (imagem em anexo).')
            ->line('**'.$post->title.'**')
            ->line('Data prevista: '.$post->whenLabel().' · Redes: '.implode(', ', $post->networks ?? []));

        if ($post->caption) {
            $mail->line('**Legenda:**');
            foreach (preg_split('/\R/', $post->caption) as $linha) {
                if (trim($linha) !== '') {
                    $mail->line($linha);
                }
            }
        }

        $mail->action('Abrir no painel', url('/').'#post-'.$post->id)
            ->line('Nada é publicado sem a sua aprovação. O painel só abre na rede da NEXUS.')
            ->salutation('Painel de Redes Sociais NEXUS');

        if ($post->image_path && is_file(public_path($post->image_path))) {
            $mail->attach(public_path($post->image_path));
        }

        return $mail;
    }
}
