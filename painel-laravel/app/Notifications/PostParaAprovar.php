<?php

namespace App\Notifications;

use App\Http\Controllers\EmailDecisionController;
use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notificacoes;

/**
 * Email ao aprovador quando um post fica "À espera de aprovação".
 * Leva as imagens em anexo, a legenda e os botões "Aprovar" e "Pedir alteração"
 * (links assinados que abrem uma página de confirmação, sem ter de entrar no painel).
 */
class PostParaAprovar extends Notification
{
    /**
     * $emNomeDe: quem decide pelos botões quando o email vai para um endereço sem conta;
     * $paraEmail: esse endereço (fica no histórico: "pelo email (…)").
     */
    public function __construct(public Post $post, public ?User $emNomeDe = null, public ?string $paraEmail = null) {}

    /**
     * Envia o aviso a quem aprova: os endereços de config('painel.emails_aprovacao') ou, se estiver vazio,
     * todas as contas do painel. $exceto = quem enviou o post (não precisa de ser avisado).
     */
    public static function enviar(Post $post, ?User $exceto = null): void
    {
        $emails = config('painel.emails_aprovacao');
        if (! $emails) {
            Notificacoes::send(User::when($exceto, fn ($q) => $q->whereKeyNot($exceto->id))->get(), new self($post));

            return;
        }

        $admin = User::where('is_admin', true)->orderBy('id')->first() ?? User::orderBy('id')->first();
        foreach ($emails as $email) {
            if ($exceto && strcasecmp($exceto->email, $email) === 0) {
                continue;
            }
            $conta = User::where('email', $email)->first();
            if ($conta) {
                $conta->notify(new self($post));
            } elseif ($admin) {
                Notificacoes::route('mail', $email)->notify(new self($post, $admin, $email));
            }
        }
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->post;
        $quem = $notifiable instanceof User ? $notifiable : $this->emNomeDe;

        // Todas as imagens (num carrossel, pela ordem em que aparecem) e a versão dos Stories, em anexo.
        $anexos = array_values(array_filter(
            array_unique(array_filter([...$post->imagens(), $post->temStories() ? $post->imagem_story : null])),
            fn ($imagem) => is_file(public_path($imagem))
        ));
        $logo = public_path('modelos/logo-nexus.png');

        $mail = (new MailMessage)
            ->subject('Post para aprovar: '.$post->title)
            ->view('emails.post-para-aprovar', [
                'post' => $post,
                'nome' => $notifiable->name ?? $quem?->name,
                'aprovar' => $quem ? EmailDecisionController::link($post, $quem, 'aprovar', $this->paraEmail) : null,
                'alterar' => $quem ? EmailDecisionController::link($post, $quem, 'alterar', $this->paraEmail) : null,
                'painel' => rtrim((string) config('app.url'), '/').'/#post-'.$post->id, // endereço oficial
                'dias' => EmailDecisionController::VALIDADE_DIAS,
                'logo' => is_file($logo) ? $logo : null,
                'capa' => $anexos ? $this->miniatura(public_path($anexos[0])) : null,
                'anexos' => count($anexos),
            ]);

        foreach ($anexos as $imagem) {
            $mail->attach(public_path($imagem));
        }

        return $mail;
    }

    /** Capa reduzida (600 px, JPEG) para aparecer dentro do email sem o tornar pesado. */
    private function miniatura(string $ficheiro): string
    {
        $destino = storage_path('app/email/capa-'.md5($ficheiro.'|'.filemtime($ficheiro)).'.jpg');
        if (is_file($destino)) {
            return $destino;
        }
        $original = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($ficheiro)) : false;
        if (! $original) {
            return $ficheiro; // sem GD: vai a imagem inteira
        }
        $largura = 600;
        $altura = (int) round(imagesy($original) * $largura / imagesx($original));
        $nova = imagecreatetruecolor($largura, $altura);
        imagecopyresampled($nova, $original, 0, 0, 0, 0, $largura, $altura, imagesx($original), imagesy($original));
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($destino));
        imagejpeg($nova, $destino, 85);

        return $destino;
    }
}
