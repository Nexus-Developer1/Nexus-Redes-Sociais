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

    /** Publica no Facebook (num carrossel, um post com as várias fotos, pela ordem). Devolve o ID do post. */
    public function publishFacebook(Post $post): string
    {
        $files = $this->files($post);
        if (count($files) === 1) {
            $res = $this->uploadPhoto($files[0], published: true, message: (string) $post->caption);

            return (string) ($res['post_id'] ?? $res['id']);
        }

        // Várias fotos: enviam-se sem publicar e depois junta-se tudo num só post.
        $media = array_map(
            fn ($file) => json_encode(['media_fbid' => $this->uploadPhoto($file, published: false)['id']]),
            $files
        );

        return (string) $this->check($this->api()->asForm()->post(config('services.meta.page_id').'/feed', [
            'message' => (string) $post->caption,
            'attached_media' => $media,
        ]))['id'];
    }

    /** Publica no Instagram (num carrossel, com todas as imagens). Devolve o ID da publicação. */
    public function publishInstagram(Post $post): string
    {
        if (! $this->instagramConfigured()) {
            throw new RuntimeException('Falta META_IG_USER_ID no .env.');
        }
        $ig = config('services.meta.instagram_user_id');
        $temporarias = [];

        try {
            // 1. Fotos temporárias no Facebook, só para ter um endereço público de cada imagem.
            $urls = [];
            foreach ($this->files($post) as $file) {
                $photoId = $this->uploadPhoto($file, published: false)['id'];
                $temporarias[] = $photoId;
                $images = $this->check($this->api()->get($photoId, ['fields' => 'images']))['images'] ?? [];
                $urls[] = $images[0]['source'] ?? throw new RuntimeException('A Meta não devolveu o endereço da imagem.');
            }

            // 2. Contentor do Instagram: uma imagem, ou um carrossel feito de várias.
            if (count($urls) === 1) {
                $container = $this->check($this->api()->asForm()->post("{$ig}/media", [
                    'image_url' => $urls[0],
                    'caption' => (string) $post->caption,
                ]))['id'];
            } else {
                $children = [];
                foreach ($urls as $url) {
                    $children[] = $this->check($this->api()->asForm()->post("{$ig}/media", [
                        'image_url' => $url,
                        'is_carousel_item' => 'true',
                    ]))['id'];
                }
                foreach ($children as $child) {
                    $this->waitUntilReady($child);
                }
                $container = $this->check($this->api()->asForm()->post("{$ig}/media", [
                    'media_type' => 'CAROUSEL',
                    'children' => implode(',', $children),
                    'caption' => (string) $post->caption,
                ]))['id'];
            }

            // 3. Esperar que o Instagram processe as imagens.
            $this->waitUntilReady($container);

            // 4. Publicar.
            return (string) $this->check($this->api()->asForm()->post("{$ig}/media_publish", [
                'creation_id' => $container,
            ]))['id'];
        } finally {
            // As fotos temporárias já não são precisas (o Instagram guarda a sua própria cópia).
            foreach ($temporarias as $photoId) {
                rescue(fn () => $this->api()->delete($photoId), report: false);
            }
        }
    }

    /** As imagens do post (num carrossel, todas, por ordem), que têm de existir no disco. */
    private function files(Post $post): array
    {
        $files = array_map(fn ($path) => public_path($path), $post->imagens());
        if (! $files) {
            throw new RuntimeException('O post não tem imagem.');
        }
        foreach ($files as $file) {
            if (! is_file($file)) {
                throw new RuntimeException('Falta a imagem '.basename($file).'. Gere a imagem outra vez no editor.');
            }
        }

        return $files;
    }

    private function uploadPhoto(string $file, bool $published, ?string $message = null): array
    {
        $data = ['published' => $published ? 'true' : 'false'];
        if ($published && $message !== null) {
            $data['message'] = $message;
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
