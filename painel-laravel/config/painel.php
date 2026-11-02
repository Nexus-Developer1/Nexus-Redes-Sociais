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
];
