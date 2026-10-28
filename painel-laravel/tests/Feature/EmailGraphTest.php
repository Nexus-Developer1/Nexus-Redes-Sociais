<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_aviso_sai_pela_api_microsoft_graph_com_anexo(): void
    {
        config([
            'mail.default' => 'msgraph',
            'mail.from.address' => 'suporte@nxs.pt',
            'mail.mailers.msgraph' => [
                'transport' => 'msgraph',
                'tenant_id' => 'TENANT',
                'client_id' => 'CLIENT',
                'client_secret' => 'SEGREDO',
                'sender' => 'suporte@nxs.pt',
            ],
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'TOKEN1', 'expires_in' => 3599]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);

        File::ensureDirectoryExists(public_path('imagens-posts'));
        File::put(public_path('imagens-posts/teste-email.png'), 'png-falso');
        $post = Post::create([
            'title' => 'Dica de teste', 'caption' => 'Legenda', 'image_path' => 'imagens-posts/teste-email.png',
            'networks' => ['Instagram'], 'status' => Post::PENDING,
        ]);
        $user = User::factory()->create(['email' => 'aprovador@exemplo.pt']);

        $user->notify(new PostParaAprovar($post));
        File::delete(public_path('imagens-posts/teste-email.png'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/TENANT/oauth2/v2.0/token')
            && $r['client_secret'] === 'SEGREDO' && $r['grant_type'] === 'client_credentials');

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/users/suporte%40nxs.pt/sendMail')) {
                return false;
            }
            $m = $r['message'];

            return $r->hasHeader('Authorization', 'Bearer TOKEN1')
                && $m['subject'] === 'Post para aprovar: Dica de teste'
                && $m['toRecipients'][0]['emailAddress']['address'] === 'aprovador@exemplo.pt'
                && $m['body']['contentType'] === 'HTML'
                && str_contains($m['body']['content'], 'Abrir no painel')
                && $m['attachments'][0]['name'] === 'teste-email.png'
                && base64_decode($m['attachments'][0]['contentBytes']) === 'png-falso';
        });
    }
}
