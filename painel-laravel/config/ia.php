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
    // qwen/qwen3.8-27b escreve melhor português de Portugal do que o gpt-oss (testado a 29/10/2026)
    'modelo' => env('IA_MODELO', 'qwen/qwen3.8-27b'),
    'timeout' => (int) env('IA_TIMEOUT', 90),

    // Calendário automático: à segunda-feira a IA propõe ideias para a semana seguinte, até este número
    // de posts por semana (0 = desligado)...
    'ideias_por_semana' => (int) env('IA_IDEIAS_POR_SEMANA', 3),
    // ...e faz cada ideia sozinha este número de dias antes da data (0 = só com o botão).
    'ideias_dias_antes' => (int) env('IA_IDEIAS_DIAS_ANTES', 7),
];
