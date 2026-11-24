<?php

namespace App\Mail;

use App\Models\Parceiro;
use App\Models\Post;
use App\Support\Miniatura;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email de um post a um parceiro (um email por pessoa: ninguém vê os outros endereços).
 * Os parceiros estão fora da rede da NEXUS: as imagens vão DENTRO do email (JPEG pequeno), nunca como link
 * para o servidor interno. Leva a legenda sem as hashtags e, no fim, como deixar de receber.
 */
class PostParaParceiros extends Mailable
{
    /** Largura das imagens dentro do email (JPEG): leves, mas nítidas num ecrã normal. */
    public const LARGURA = 720;

    /** $parceiro = null: email de teste para quem está no painel ($nomeTeste). */
    public function __construct(public Post $post, public ?Parceiro $parceiro = null, public ?string $nomeTeste = null, public bool $teste = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->teste ? '[Teste] ' : '').$this->post->assuntoParceiros(),
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        $imagens = array_values(array_filter(array_map(
            fn ($img) => Miniatura::caminho($img, self::LARGURA),
            $this->post->imagens()
        )));
        $logo = public_path('modelos/logo-nexus.png');
        $remover = 'mailto:'.config('mail.from.address').'?subject='.rawurlencode('Remover da lista de parceiros NEXUS');

        return new Content(view: 'emails.post-parceiros', with: [
            'post' => $this->post,
            'nome' => $this->parceiro?->nome ?? $this->nomeTeste,
            'imagens' => $imagens,
            'legenda' => $this->post->legendaParaEmail(),
            'logo' => is_file($logo) ? $logo : null,
            'remover' => $remover,
            'teste' => $this->teste,
        ]);
    }
}
