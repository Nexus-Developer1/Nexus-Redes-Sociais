<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Publica posts na página de Facebook e no Instagram da NEXUS pela API Graph da Meta.
 *
 * O Instagram só aceita imagens num endereço público, e o painel só está na rede interna.
 * Por isso a imagem é primeiro enviada para o Facebook como foto não publicada; o endereço
 * dessa foto (servidores da Meta) é o que se dá ao Instagram. No fim a foto temporária é apagada.
 */
class MetaPublisher
{
    public function configured(): bool
    {
        return filled(config('services.meta.page_id')) && filled(config('services.meta.page_token'));
    }

    public function instagramConfigured(): bool
    {
        return $this->configured() && filled(config('services.meta.instagram_user_id'));
    }

    /** Publica no Facebook. Devolve o ID do post. */
    public function publishFacebook(Post $post): string
    {
        $res = $this->uploadPhoto($post, published: true);

        return (string) ($res['post_id'] ?? $res['id']);
    }

    /** Publica no Instagram. Devolve o ID da publicação. */
    public function publishInstagram(Post $post): string
    {
        if (! $this->instagramConfigured()) {
            throw new RuntimeException('Falta META_IG_USER_ID no .env.');
        }
        $ig = config('services.meta.instagram_user_id');

        // 1. Foto temporária no Facebook, só para ter um endereço público da imagem.
        $photoId = $this->uploadPhoto($post, published: false)['id'];

        try {
            $images = $this->check($this->api()->get($photoId, ['fields' => 'images']))['images'] ?? [];
            $url = $images[0]['source'] ?? throw new RuntimeException('A Meta não devolveu o endereço da imagem.');

            // 2. Contentor do Instagram com a imagem e a legenda.
            $container = $this->check($this->api()->asForm()->post("{$ig}/media", [
                'image_url' => $url,
                'caption' => (string) $post->caption,
            ]))['id'];

            // 3. Esperar que o Instagram processe a imagem.
            $this->waitUntilReady($container);

            // 4. Publicar.
            return (string) $this->check($this->api()->asForm()->post("{$ig}/media_publish", [
                'creation_id' => $container,
            ]))['id'];
        } finally {
            // A foto temporária já não é precisa (o Instagram guarda a sua própria cópia).
            rescue(fn () => $this->api()->delete($photoId), report: false);
        }
    }

    private function uploadPhoto(Post $post, bool $published): array
    {
        $file = $post->image_path ? public_path($post->image_path) : null;
        if (! $file || ! is_file($file)) {
            throw new RuntimeException('O post não tem imagem.');
        }

        $data = ['published' => $published ? 'true' : 'false'];
        if ($published) {
            $data['message'] = (string) $post->caption;
        }

        return $this->check(
            $this->api()
                ->attach('source', file_get_contents($file), basename($file))
                ->post(config('services.meta.page_id').'/photos', $data)
        );
    }

    private function waitUntilReady(string $container): void
    {
        for ($i = 0; $i < 20; $i++) {
            $status = $this->check($this->api()->get($container, ['fields' => 'status_code']))['status_code'] ?? null;
            if ($status === 'FINISHED') {
                return;
            }
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException("O Instagram não aceitou a imagem (estado {$status}).");
            }
            Sleep::for(3)->seconds();
        }

        throw new RuntimeException('O Instagram demorou demasiado a processar a imagem.');
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.config('services.meta.graph_version'))
            ->withToken(config('services.meta.page_token'))
            ->acceptJson()
            ->timeout(90);
    }

    /** Devolve o JSON da resposta, ou lança um erro legível com a mensagem da Meta. */
    private function check(Response $res): array
    {
        if ($res->failed()) {
            $msg = $res->json('error.message') ?? $res->body();
            throw new RuntimeException("Meta respondeu {$res->status()}: {$msg}");
        }

        return $res->json() ?? [];
    }
}
