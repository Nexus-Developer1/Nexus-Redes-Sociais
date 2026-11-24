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

    // Página "Notícias de IA": o agente recolhe estes feeds (RSS/Atom) à segunda e à quinta-feira e a IA faz o resumo.
    // Testados a 24 nov 2026 a partir do servidor (VentureBeat bloqueia com 429; Anthropic não tem feed).
    'fontes_noticias' => [
        'OpenAI' => 'https://openai.com/news/rss.xml',
        'Google AI' => 'https://blog.google/technology/ai/rss/',
        'NVIDIA' => 'https://blogs.nvidia.com/feed/',
        'Hugging Face' => 'https://huggingface.co/blog/feed.xml',
        'Microsoft' => 'https://news.microsoft.com/source/topics/ai/feed/',
        'AWS Machine Learning' => 'https://aws.amazon.com/blogs/machine-learning/feed/',
        'TechCrunch' => 'https://techcrunch.com/category/artificial-intelligence/feed/',
        'The Verge' => 'https://www.theverge.com/rss/ai-artificial-intelligence/index.xml',
        'MIT Technology Review' => 'https://www.technologyreview.com/topic/artificial-intelligence/feed',
    ],

    // Parceiros tecnológicos da NEXUS (separador "Parceiros" da mesma página): marca => feed. Testados a 24 nov.
    // Intel, Netgear, Ubiquiti e Micron não têm RSS que funcione (a Intel recusa pedidos automáticos): vão pelo
    // Google Notícias (Intel, Micron e Ubiquiti só com páginas do site oficial). A Altaro é da Hornetsecurity (o blog passou para lá).
    'fontes_parceiros' => [
        'AMD' => 'https://ir.amd.com/news-events/press-releases/rss',
        'Intel' => 'https://news.google.com/rss/search?hl=en-US&gl=US&ceid=US:en&q=site:intel.com+newsroom+when:30d',
        'NVIDIA' => 'https://nvidianews.nvidia.com/releases.xml',
        'Supermicro' => 'https://ir.supermicro.com/rss/pressrelease.aspx',
        // Micron: só os comunicados oficiais (o site todo traz ofertas de emprego)
        'Micron' => 'https://news.google.com/rss/search?hl=en-US&gl=US&ceid=US:en&q=site:investors.micron.com+when:30d',
        'Microsoft' => 'https://blogs.microsoft.com/feed/',
        'VMware' => 'https://blogs.vmware.com/feed',
        'DataCore' => 'https://www.datacore.com/feed/',
        'Open-E' => 'https://www.open-e.com/blog/feed/',
        'Altaro' => 'https://www.hornetsecurity.com/en/feed/',
        'Riello UPS' => 'https://www.riello-ups.com/feed/',
        // Netgear: o site só dá páginas de produto, por isso vão as notícias de lançamentos (de outros sites)
        'Netgear' => 'https://news.google.com/rss/search?hl=en-US&gl=US&ceid=US:en&q=NETGEAR+(launches+OR+introduces+OR+unveils)+when:30d',
        // Ubiquiti: o blog oficial e as versões de software / avisos de segurança (sem o fórum)
        'Ubiquiti' => 'https://news.google.com/rss/search?hl=en-US&gl=US&ceid=US:en&q=site:blog.ui.com+OR+site:community.ui.com/releases+when:30d',
    ],
];
