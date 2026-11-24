<?php

return [

    // Serviços externos do painel. As chaves ficam só no .env do servidor.

    // Publicação no Facebook e Instagram (API Graph da Meta). Sem token, nada é publicado.
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'page_id' => env('META_PAGE_ID'),
        'instagram_user_id' => env('META_IG_USER_ID'),
        'page_token' => env('META_PAGE_TOKEN'),
    ],

    // Fotos gratuitas para a imagem de fundo dos posts (pexels.com/api). Vazio = desligado.
    'pexels' => [
        'key' => env('PEXELS_CHAVE'),
    ],
    // Alternativa ao Pexels (pixabay.com/api/docs): usada quando não há chave do Pexels.
    'pixabay' => [
        'key' => env('PIXABAY_CHAVE'),
    ],

    // Browser sem janela que gera as imagens dos posts. Vazio = procura o chrome-headless-shell (/opt) ou o Edge.
    'render' => [
        'browser' => env('RENDER_BROWSER'),
        // No servidor: o browser corre com este utilizador (sem acesso ao .env), através do sudo,
        // e grava numa pasta partilhada com ele. Vazio = corre com o utilizador da aplicação (ex.: Windows).
        'utilizador' => env('RENDER_UTILIZADOR'),
        'pasta' => env('RENDER_PASTA'),
    ],

];
