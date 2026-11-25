<?php

namespace App\Http\Controllers;

use App\Mail\PostParaParceiros;
use App\Models\Parceiro;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Vertente email do post: enviar ou não aos parceiros, ver o email e enviar um teste. */
class PostParceirosController extends Controller
{
    /**
     * "Enviar também aos parceiros" (menu "⋯" do cartão). Só antes de aprovar: o que sai por email
     * também tem de ser aprovado por uma pessoa. Depois de enviado, já não muda.
     */
    public function partners(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeEditable($post);
        abort_if($post->parceiros_enviado_em !== null, 409, 'Este post já foi enviado aos parceiros.');
        $quer = $request->boolean('enviar');
        if ($quer !== $post->enviar_parceiros) {
            $post->update(['enviar_parceiros' => $quer]);
            $post->reviews()->create(['user_id' => $request->user()->id, 'action' => $quer ? 'partners_on' : 'partners_off']);
        }
        $quantos = Parceiro::quantosAtivos();

        return back()->with('ok', $quer
            ? "“{$post->title}” vai também por email a {$quantos} ".($quantos === 1 ? 'parceiro' : 'parceiros').', à hora da publicação, depois de aprovado.'
            : "“{$post->title}” já não vai por email aos parceiros.");
    }

    /** Vertente email: o email aos parceiros tal como sai (as imagens vêm dentro da página). */
    public function partnersPreview(Request $request, Post $post): Response
    {
        $html = (new PostParaParceiros($post, null, '[nome do parceiro]'))->render();

        return response($html)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    /** Email de teste para quem está no painel: ver como os parceiros recebem este post, antes de aprovar. */
    public function partnersTest(Request $request, Post $post): RedirectResponse
    {
        if (! config('painel.emails_ligados')) {
            return back()->with('erro', 'Os emails estão desligados por agora: use “Ver o email” para ver como fica.');
        }
        if (! $post->image_path) {
            return back()->with('erro', 'Gere a imagem do post antes de enviar um teste.');
        }
        try {
            Mail::to($request->user()->email, $request->user()->name)
                ->send(new PostParaParceiros($post, null, '[nome do parceiro]', teste: true));
        } catch (Throwable $e) {
            report($e);

            return back()->with('erro', 'O email de teste não foi enviado: '.$e->getMessage());
        }
        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'partners_test', 'comment' => $request->user()->email]);

        return back()->with('ok', "Email de teste enviado para {$request->user()->email}.");
    }
}
