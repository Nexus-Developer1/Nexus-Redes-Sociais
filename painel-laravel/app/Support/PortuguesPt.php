<?php

namespace App\Support;

/**
 * Rede de segurança para o português de Portugal em tudo o que vem da IA:
 * troca os brasileirismos mais comuns e limpa os caracteres especiais.
 */
class PortuguesPt
{
    /** Brasileirismos que se corrigem sempre (palavra inteira; a maiúscula inicial mantém-se). */
    public const PALAVRAS = [
        'detectar' => 'detetar', 'detecta' => 'deteta', 'detecte' => 'detete', 'detectam' => 'detetam',
        'detectado' => 'detetado', 'detectada' => 'detetada', 'detectados' => 'detetados', 'detectadas' => 'detetadas',
        'detecção' => 'deteção', 'detecções' => 'deteções',
        'equipe' => 'equipa', 'equipes' => 'equipas',
        'roteamento' => 'encaminhamento', 'governança' => 'governação',
        'registrou' => 'registou', 'registraram' => 'registaram',
        'usuário' => 'utilizador', 'usuários' => 'utilizadores', 'usuária' => 'utilizadora',
        'tela' => 'ecrã', 'telas' => 'ecrãs', 'celular' => 'telemóvel', 'celulares' => 'telemóveis',
        'arquivo' => 'ficheiro', 'arquivos' => 'ficheiros',
        'contato' => 'contacto', 'contatos' => 'contactos', 'contate' => 'contacte', 'contate-nos' => 'contacte-nos',
        'registro' => 'registo', 'registros' => 'registos', 'cadastro' => 'registo', 'cadastre-se' => 'registe-se',
        'aplicativo' => 'aplicação', 'aplicativos' => 'aplicações',
        'gerenciar' => 'gerir', 'gerencie' => 'gira', 'gerenciamento' => 'gestão',
        'treinamento' => 'formação', 'treinamentos' => 'formações',
        'planilha' => 'folha de cálculo', 'planilhas' => 'folhas de cálculo',
        'ônibus' => 'autocarro', 'trem' => 'comboio', 'xícara' => 'chávena',
        'esporte' => 'desporto', 'salvar' => 'guardar', 'salve' => 'guarde', 'salvem' => 'guardem',
    ];

    /** Expressões em que a palavra muda de género ("na tela" → "no ecrã"); aplicam-se antes das palavras soltas. */
    public const EXPRESSOES = [
        'na tela' => 'no ecrã', 'da tela' => 'do ecrã', 'a tela' => 'o ecrã', 'uma tela' => 'um ecrã', 'pela tela' => 'pelo ecrã',
        'nas telas' => 'nos ecrãs', 'das telas' => 'dos ecrãs', 'as telas' => 'os ecrãs',
        'o aplicativo' => 'a aplicação', 'do aplicativo' => 'da aplicação', 'no aplicativo' => 'na aplicação',
        'um aplicativo' => 'uma aplicação', 'pelo aplicativo' => 'pela aplicação', 'os aplicativos' => 'as aplicações',
        'dos aplicativos' => 'das aplicações', 'nos aplicativos' => 'nas aplicações',
        'o treinamento' => 'a formação', 'do treinamento' => 'da formação', 'no treinamento' => 'na formação',
        'um treinamento' => 'uma formação', 'o gerenciamento' => 'a gestão', 'do gerenciamento' => 'da gestão',
        'no gerenciamento' => 'na gestão', 'um gerenciamento' => 'uma gestão',
    ];

    /** Troca os brasileirismos e os acentos fechados do Brasil (eletrônico → eletrónico, gênero → género). */
    public static function corrigir(string $texto): string
    {
        foreach (self::EXPRESSOES as $br => $pt) {
            $texto = preg_replace_callback('/(?<![\p{L}#@])'.preg_quote($br, '/').'(?![\p{L}])/iu', function ($m) use ($pt) {
                return mb_substr($m[0], 0, 1) !== mb_strtolower(mb_substr($m[0], 0, 1))
                    ? mb_strtoupper(mb_substr($pt, 0, 1)).mb_substr($pt, 1)
                    : $pt;
            }, $texto);
        }
        $texto = preg_replace_callback('/(?<![\p{L}#@])(\p{L}[\p{L}-]*)(?![\p{L}])/u', function ($m) {
            $palavra = $m[1];
            $minuscula = mb_strtolower($palavra);
            if (! isset(self::PALAVRAS[$minuscula])) {
                return $palavra;
            }
            $nova = self::PALAVRAS[$minuscula];
            if ($palavra === mb_strtoupper($palavra) && mb_strlen($palavra) > 1) {
                return mb_strtoupper($nova);
            }

            return mb_substr($palavra, 0, 1) !== mb_substr($minuscula, 0, 1)
                ? mb_strtoupper(mb_substr($nova, 0, 1)).mb_substr($nova, 1)
                : $nova;
        }, $texto);

        // ô/ê antes de m/n + vogal: eletrônico, econômico, gênero, acadêmico (não toca em "têm", "vêm").
        return preg_replace(['/ô(?=[mn][aeiouáéíóú])/u', '/ê(?=[mn][aeiouáéíóú])/u', '/Ô(?=[MN][AEIOU])/u', '/Ê(?=[MN][AEIOU])/u'],
            ['ó', 'é', 'Ó', 'É'], $texto);
    }

    /**
     * Limpa o texto que vem da IA (também dentro de listas): "\n" escrito à letra passa a mudança de linha,
     * hífens e espaços "especiais" (ex.: U+2011 em "e‑mail") passam a normais, para não estragar o site e o email,
     * e o **negrito** de markdown sai (o Instagram mostra os asteriscos). Depois corrige o português.
     */
    public static function limpar(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => self::limpar($x), $v);
        }
        if (! is_string($v)) {
            return $v;
        }

        return self::corrigir(strtr(str_replace(['\r\n', '\n', '**'], ["\n", "\n", ''], $v), [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{00AD}" => '',
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '',
        ]));
    }
}
