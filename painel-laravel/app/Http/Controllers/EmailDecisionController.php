<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Aprovar ou pedir alteração a partir do email, sem entrar no painel.
 *
 * O link do email é assinado (só serve para este post, esta pessoa e esta versão, durante 7 dias)
 * e abre uma página de confirmação: o Microsoft 365 abre os links dos emails para os verificar,
 * por isso nada é decidido só por abrir o link. A decisão fica no histórico em nome de quem recebeu o email.
 */
class EmailDecisionController extends Controller
{
    public const ACOES = ['aprovar', 'alterar'];

    public const VALIDADE_DIAS = 7;

    /** Link assinado (relativo ao painel, para funcionar com o IP ou com um nome) para o email. */
    public static function link(Post $post, User $user, string $acao): string
    {
        return url(URL::temporarySignedRoute('email.decisao', now()->addDays(self::VALIDADE_DIAS), [
            'post' => $post->id,
            'acao' => $acao,
            'u' => $user->id,
            'v' => (string) $post->updated_at?->timestamp,
        ], absolute: false));
    }

    public function show(Request $request, Post $post, string $acao): View
    {
        return $this->pagina($request, $post, $acao);
    }

    public function store(Request $request, Post $post, string $acao): View
    {
        [$user, $problema] = $this->verificar($request, $post);
        if ($problema) {
            return $this->pagina($request, $post, $acao);
        }

        if ($acao === 'aprovar') {
            $post->decide('approved', Post::APPROVED, $user, 'pelo email');

            return $this->pagina($request, $post, $acao, "“{$post->title}” foi aprovado e agendado para {$post->whenLabel()}.");
        }

        $dados = $request->validate(
            ['comment' => ['required', 'string', 'max:2000']],
            ['comment.required' => 'Escreva o que quer mudar.']
        );
        $post->decide('change_requested', Post::REVISING, $user, $dados['comment']);

        return $this->pagina($request, $post, $acao, 'Pedido de alteração enviado. Quando o post estiver refeito, recebe outro email.');
    }

    /** @return array{0: ?User, 1: ?string} a pessoa do link e, se não der para decidir, porquê */
    private function verificar(Request $request, Post $post): array
    {
        $user = User::find($request->query('u'));
        if (! $user) {
            return [null, 'Este link já não é válido. Abra o painel para decidir.'];
        }
        if ($post->status !== Post::PENDING) {
            return [$user, "Este post já não está à espera de aprovação (agora: {$post->statusLabel()})."];
        }
        if ((string) $request->query('v') !== (string) $post->updated_at?->timestamp) {
            return [$user, 'O post foi alterado depois de este email ser enviado. Veja a versão nova no painel antes de decidir.'];
        }

        return [$user, null];
    }

    private function pagina(Request $request, Post $post, string $acao, ?string $feito = null): View
    {
        [$user, $problema] = $feito ? [null, null] : $this->verificar($request, $post);

        return view('email-decisao', [
            'post' => $post,
            'acao' => $acao,
            'user' => $user,
            'problema' => $problema,
            'feito' => $feito,
            // o formulário volta ao mesmo link assinado
            'destino' => $request->getRequestUri(),
        ]);
    }
}
