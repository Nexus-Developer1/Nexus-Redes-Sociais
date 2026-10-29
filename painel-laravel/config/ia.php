<?php

/*
|--------------------------------------------------------------------------
| IA que escreve os posts (pedidos do painel e alterações pedidas)
|--------------------------------------------------------------------------
| Fornecedor com API no formato OpenAI (Groq para testes, grátis).
| Sem IA_CHAVE nada é feito: os pedidos ficam à espera, como antes.
| A imagem não é feita pela IA: a IA só escreve os textos e escolhe o modelo;
| o servidor monta a imagem com os modelos (public/modelos).
*/

return [
    'endereco' => env('IA_ENDERECO', 'https://api.groq.com/openai/v1'),
    'chave' => env('IA_CHAVE'),
    'modelo' => env('IA_MODELO', 'openai/gpt-oss-120b'),
    'timeout' => (int) env('IA_TIMEOUT', 90),
];
