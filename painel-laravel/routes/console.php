<?php

use Illuminate\Support\Facades\Schedule;

// No servidor, o cron corre "php artisan schedule:run" a cada minuto.
// runInBackground: as tarefas correm ao mesmo tempo (uma IA lenta não atrasa as outras).

// Publica os posts aprovados cuja hora chegou.
Schedule::command('post:publicar')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();

// A IA trata dos pedidos de post e das alterações pedidas (sem IA_CHAVE não faz nada).
Schedule::command('post:tratar-pedidos')->everyMinute()->withoutOverlapping(15)->runInBackground();

// Calendário automático: troca temas pedidos, faz as ideias uns dias antes da data e propõe as da semana seguinte.
Schedule::command('post:calendario-ia')->everyMinute()->withoutOverlapping(15)->runInBackground();

// Envia por email aos parceiros os posts aprovados marcados com "Enviar também aos parceiros", à hora marcada.
Schedule::command('post:enviar-parceiros')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();

// Lembrete por email dos posts ainda por aprovar a menos de 48 h da data (de hora a hora, em horário de expediente).
Schedule::command('post:lembrar')->hourly()->between('8:00', '20:00')->withoutOverlapping(30)->runInBackground();
