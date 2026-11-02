<?php

namespace App\Notifications;

use App\Http\Controllers\EmailDecisionController;
use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email ao aprovador quando um post fica "À espera de aprovação".
 * Leva as imagens em anexo, a legenda e os botões "Aprovar" e "Pedir alteração"
 * (links assinados que abrem uma página de confirmação, sem ter de entrar no painel).
 */
class PostParaAprovar extends Notification
{
    /** $emNomeDe: quem decide pelos botões quando o email vai para um endereço sem conta (ex.: email de teste). */
    public function __construct(public Post $post, public ?User $emNomeDe = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->post;
        $quem = $notifiable instanceof User ? $notifiable : $this->emNomeDe;

        $mail = (new MailMessage)
            ->subject('Post para aprovar: '.$post->title)
            ->markdown('emails.post-para-aprovar', [
                'post' => $post,
                'nome' => $notifiable->name ?? $quem?->name,
                'aprovar' => $quem ? EmailDecisionController::link($post, $quem, 'aprovar') : null,
                'alterar' => $quem ? EmailDecisionController::link($post, $quem, 'alterar') : null,
                'painel' => url('/').'#post-'.$post->id,
                'dias' => EmailDecisionController::VALIDADE_DIAS,
            ]);

        // Todas as imagens (num carrossel, pela ordem em que aparecem) e a versão dos Stories.
        foreach (array_unique(array_filter([...$post->imagens(), $post->temStories() ? $post->imagem_story : null])) as $imagem) {
            if (is_file(public_path($imagem))) {
                $mail->attach(public_path($imagem));
            }
        }

        return $mail;
    }
}
