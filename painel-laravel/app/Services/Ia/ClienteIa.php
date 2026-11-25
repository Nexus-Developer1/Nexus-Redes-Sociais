<?php

namespace App\Services\Ia;

use App\Support\PortuguesPt;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ligação à IA de texto (API no formato OpenAI, ex.: Groq; config/ia.php) e o revisor de português,
 * usados por todas as peças da IA (posts, ideias do calendário, fotos e notícias).
 */
class ClienteIa
{
    public function configurado(): bool
    {
        return filled(config('ia.chave'));
    }

    public function exigirConfigurado(): void
    {
        if (! $this->configurado()) {
            throw new RuntimeException('A IA não está configurada (IA_CHAVE no .env).');
        }
    }

    /**
     * Chamada à IA. Se o plano gratuito pedir para esperar (429 com retry-after até 30 s),
     * espera e tenta outra vez, até 3 vezes, em vez de desistir.
     */
    public function chamar(int $timeout, array $pedido): Response
    {
        for ($tentativa = 1; ; $tentativa++) {
            $resposta = Http::withToken(config('ia.chave'))->timeout($timeout)->acceptJson()
                ->post(rtrim(config('ia.endereco'), '/').'/chat/completions', ['model' => config('ia.modelo')] + $pedido);
            $espera = (float) $resposta->header('retry-after');
            if ($resposta->status() !== 429 || $tentativa >= 3 || $espera <= 0 || $espera > 30) {
                return $resposta;
            }
            usleep((int) (($espera + 0.5) * 1_000_000));
        }
    }

    /** O texto da resposta (a mensagem da IA). */
    public static function texto(Response $resposta): string
    {
        return (string) $resposta->json('choices.0.message.content');
    }

    /** Erro com a mensagem que a IA devolveu (ex.: 429 = limite do plano gratuito). */
    public static function erro(Response $resposta): RuntimeException
    {
        return new RuntimeException('A IA respondeu com erro '.$resposta->status().': '
            .mb_substr((string) ($resposta->json('error.message') ?? $resposta->body()), 0, 300));
    }

    /**
     * Revisor de português europeu: uma segunda passagem da IA que só corrige brasileirismos,
     * ortografia e pontuação, sem mudar o conteúdo. Se a IA falhar, dá erro (sem revisão o texto não se usa).
     *
     * @param  array<string, string>  $textos
     * @return array<string, string>
     */
    public function rever(array $textos): array
    {
        $textos = array_filter($textos, fn ($t) => is_string($t) && trim($t) !== '');
        if (! $textos) {
            return [];
        }
        $revistos = null;
        for ($tentativa = 1; $tentativa <= 2 && ! is_array($revistos); $tentativa++) {
            $resposta = $this->chamar(60, [
                'messages' => [
                    ['role' => 'system', 'content' => 'És revisor de português europeu (de Portugal) de uma empresa portuguesa. '
                        .'Recebes um objeto JSON com textos. Devolve o MESMO objeto JSON, com as mesmas chaves, corrigindo só: '
                        .'brasileirismos de vocabulário (equipe→equipa, tela→ecrã, usuário→utilizador, arquivo→ficheiro, contato→contacto, '
                        .'celular→telemóvel, time→equipa), de ortografia (detectar→detetar, fato→facto, econômico→económico) e de construção '
                        .'(gerúndio "estou fazendo"→"estou a fazer", "segue sendo"/"segue como"→"continua a ser", "você pode estar"→"pode estar"), '
                        .'mistura de "tu" e "você" (usa sempre "você"/"a sua empresa", sem "tu", "teu", "fica atento"; nas ordens e conselhos '
                        .'usa o imperativo de "você": Garanta, Proteja, Faça, Descubra, Fale, Saiba, e não Garante, Protege, Faz, Descobre, Fala, Sabe), '
                        .'e erros de ortografia, acentuação e pontuação. '
                        .'NÃO acrescentes nem tires informação, não mudes o sentido, o tom, os emojis, as mudanças de linha, os asteriscos, '
                        .'os endereços nem as hashtags (nas hashtags só corriges a grafia). Se um texto já estiver certo, devolve-o igual.'],
                    ['role' => 'user', 'content' => json_encode($textos, JSON_UNESCAPED_UNICODE)],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0,
            ]);
            if ($resposta->failed()) {
                // Sem revisão o texto não se usa (o pedido volta à fila / o botão avisa).
                throw new RuntimeException('O revisor de português não conseguiu rever o texto (a IA respondeu com erro '.$resposta->status().'). Tente daqui a um minuto.');
            }
            $revistos = json_decode(self::texto($resposta), true);
        }
        if (! is_array($revistos)) {
            throw new RuntimeException('O revisor de português não devolveu uma revisão válida. Tente outra vez.');
        }
        // Só aceita a revisão de cada texto se veio como texto e não ficou muito diferente em tamanho.
        foreach ($textos as $chave => $original) {
            $novo = $revistos[$chave] ?? null;
            if (is_string($novo) && trim($novo) !== '' && mb_strlen($novo) <= mb_strlen($original) * 1.3 + 20) {
                $textos[$chave] = PortuguesPt::limpar($novo);
            }
        }

        return $textos;
    }
}
