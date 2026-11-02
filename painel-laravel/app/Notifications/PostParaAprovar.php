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
        if ($post->imagens_carrossel) {
            $mail->line('Carrossel com '.count($post->imagens_carrossel).' imagens (vão todas em anexo, por ordem).');
        }

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

        // Todas as imagens (num carrossel, pela ordem em que aparecem) e a versão dos Stories.
        foreach (array_unique(array_filter([...$post->imagens(), $post->temStories() ? $post->imagem_story : null])) as $imagem) {
            if (is_file(public_path($imagem))) {
                $mail->attach(public_path($imagem));
            }
        }

        return $mail;
    }
}
