<?php

// Publica os posts aprovados cuja hora chegou. No servidor, o cron corre "php artisan schedule:run" a cada minuto.
Illuminate\Support\Facades\Schedule::command('post:publicar')->everyFiveMinutes()->withoutOverlapping(30);

// A IA trata dos pedidos de post e das alterações pedidas (sem IA_CHAVE não faz nada).
Illuminate\Support\Facades\Schedule::command('post:tratar-pedidos')->everyMinute()->withoutOverlapping(15);

// Calendário automático: troca temas pedidos, faz as ideias uns dias antes da data e propõe as da semana seguinte.
Illuminate\Support\Facades\Schedule::command('post:calendario-ia')->everyMinute()->withoutOverlapping(15);
