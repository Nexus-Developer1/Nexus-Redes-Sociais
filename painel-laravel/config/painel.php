<?php

/*
|--------------------------------------------------------------------------
| Painel de aprovação
|--------------------------------------------------------------------------
*/

return [
    // Quem recebe o email "Post para aprovar" (separados por vírgulas). Vazio = todas as contas do painel.
    // Quem não tem conta decide pelos botões do email em nome do primeiro administrador; o histórico guarda o email.
    'emails_aprovacao' => array_values(array_filter(array_map('trim', explode(',', (string) env('EMAILS_APROVACAO', ''))))),

    // Interruptor geral dos emails (aprovação, lembretes, parceiros e testes). EMAILS_LIGADOS=false = não sai nenhum;
    // os posts continuam a ser feitos e ficam no painel. Nada fica marcado como enviado, por isso ao ligar segue normalmente.
    'emails_ligados' => (bool) env('EMAILS_LIGADOS', true),

    // Nome com que os emails aos parceiros saem (o endereço é o de MAIL_FROM_ADDRESS).
    'parceiros_remetente' => env('PARCEIROS_REMETENTE', 'NEXUS IT Solutions'),

    // Capa do email aos parceiros feita pelo browser (cabeçalho verde + foto + tema). Desligada nos testes.
    'email_capa' => (bool) env('EMAIL_CAPA', true),

    // Pausa entre emails aos parceiros (ms): o Microsoft 365 aceita até 30 emails por minuto de cada caixa.
    'parceiros_pausa_ms' => (int) env('PARCEIROS_PAUSA_MS', 2100),
];
