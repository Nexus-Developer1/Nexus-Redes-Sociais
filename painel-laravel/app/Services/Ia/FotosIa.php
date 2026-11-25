<?php

namespace App\Services\Ia;

use RuntimeException;

/** A IA ajuda nas fotos de fundo: escolhe a foto certa e escreve as pesquisas em inglês para os bancos de fotos. */
class FotosIa
{
    public function __construct(private ClienteIa $ia) {}

    /**
     * Entre as fotos encontradas no banco de fotos, escolhe a que serve de fundo para o post
     * (pela descrição de cada uma). Devolve null se nenhuma servir: mais vale sem foto do que errada.
     *
     * @param  list<array{id: int, alt: string}>  $fotos
     */
    public function escolher(string $tema, array $fotos): ?int
    {
        if (! $fotos) {
            return null;
        }
        $lista = collect($fotos)->map(fn ($f) => $f['id'].': '.mb_substr($f['alt'], 0, 150))->implode("\n");
        $resposta = $this->ia->chamar(30, [
                'messages' => [
                    ['role' => 'system', 'content' => 'Escolhes a fotografia de fundo para um post de uma empresa portuguesa de informática '
                        .'(servidores, computadores, redes, energia/UPS, cibersegurança, backup, escritórios). Cada linha é "id: palavras que descrevem a foto". '
                        .'REGRAS: 1) A foto TEM de mostrar informática: computadores, portáteis, ecrãs, servidores, datacenter, cabos e equipamento de rede, '
                        .'discos, técnicos a trabalhar com equipamento ou escritórios com computadores. '
                        .'2) TEM de combinar com o assunto do post (ex.: num post de backup, discos, servidores ou armazenamento; uma foto de VPN, '
                        .'de telemóvel ou de uma aplicação não serve para backup). '
                        .'3) NÃO servem: pessoas ou interiores sem tecnologia à vista, comida, animais, plantas, paisagens, desporto, dinheiro, '
                        .'nem fotos de uma marca ou aplicação concreta (vpn, logo, brand, app, nomes de marcas). '
                        .'4) Segue o estilo do feed da NEXUS: prefere equipamento real e bem iluminado (servidores, bastidores, cabos de rede, '
                        .'workstations, UPS, discos), técnicos a trabalhar com equipamento e escritórios modernos com computadores. '
                        .'Evita clichés: hackers encapuzados, máscaras, cadeados digitais, ecrãs de código no escuro, dinheiro. '
                        .'Se nenhuma cumprir as regras, responde 0. Responde só com o número.'],
                    ['role' => 'user', 'content' => "Tema do post: {$tema}\n\nFotos:\n{$lista}"],
                ],
                'temperature' => 0,
            ]);
        if ($resposta->failed()) {
            // Não é "nenhuma serve": a IA não respondeu (ex.: limite do plano gratuito). Quem chama decide o que fazer.
            throw new RuntimeException('A IA não conseguiu escolher a foto (erro '.$resposta->status().').');
        }
        preg_match('/\d+/', ClienteIa::texto($resposta), $m);
        $id = (int) ($m[0] ?? 0);

        return in_array($id, array_column($fotos, 'id'), true) ? $id : null;
    }

    /**
     * "Outra foto": palavras em inglês para uma foto de fundo que mostre uma cena DIFERENTE das pesquisas já feitas
     * (ex.: depois de "network cables" → "it technician server room"), para a foto nova não ser quase igual.
     */
    public function pesquisaNova(string $tema, array $jaUsadas): string
    {
        $resposta = $this->ia->chamar(20, [
            'messages' => [
                ['role' => 'system', 'content' => 'Dás 2 a 4 palavras-chave em inglês para procurar uma fotografia de fundo num banco de imagens, '
                    .'para um post de uma empresa de informática. A foto tem de mostrar SEMPRE informática ligada ao tema (computadores, '
                    .'servidores, datacenter, discos, redes e cabos, técnicos com equipamento, escritório com computadores) e uma cena claramente '
                    .'DIFERENTE das pesquisas já usadas (outro equipamento, outro sítio, ou um técnico em vez de só objetos). Sem siglas, marcas nem aplicações. '
                    .'Responde só com as palavras, sem aspas nem pontuação.'],
                ['role' => 'user', 'content' => 'Tema: '.mb_substr($tema, 0, 200)."\nPesquisas já usadas: ".implode('; ', array_slice($jaUsadas, -8))],
            ],
            'temperature' => 0.9,
        ]);
        $texto = trim(ClienteIa::texto($resposta), " \t\n\r\"'.");

        return $resposta->successful() && $texto !== '' ? mb_substr($texto, 0, 80) : '';
    }

    /** Passa uma pesquisa de fotos escrita em português para palavras-chave em inglês (os bancos de fotos funcionam melhor). */
    public function pesquisaEmIngles(string $pesquisa): string
    {
        $resposta = $this->ia->chamar(20, [
                'messages' => [
                    ['role' => 'system', 'content' => 'Traduz o pedido para 2 a 5 palavras-chave em inglês, para pesquisar fotografias num banco de imagens. Responde só com as palavras, sem aspas nem pontuação.'],
                    ['role' => 'user', 'content' => mb_substr($pesquisa, 0, 200)],
                ],
                'temperature' => 0,
            ]);
        $texto = trim(ClienteIa::texto($resposta), " \t\n\r\"'.");

        return $resposta->successful() && $texto !== '' ? mb_substr($texto, 0, 80) : $pesquisa;
    }
}
