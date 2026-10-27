<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Primeiro aprovador. A palavra-passe vem do .env (APROVADOR_PASSWORD);
        // se estiver vazia, é gerada uma e mostrada aqui uma única vez.
        $email = env('APROVADOR_EMAIL', 'aprovador@nexus-solutions.pt');
        if (! User::where('email', $email)->exists()) {
            $password = env('APROVADOR_PASSWORD') ?: Str::password(14, symbols: false);
            User::create([
                'name' => env('APROVADOR_NOME', 'Aprovador'),
                'email' => $email,
                'password' => $password,
            ]);
            if (! env('APROVADOR_PASSWORD')) {
                $this->command?->warn("Aprovador criado: {$email} / palavra-passe: {$password}");
            }
        }

        if (Post::exists()) {
            return; // não duplica os exemplos
        }

        // Os dois posts já feitos (pasta posts/ do projeto).
        Post::create([
            'title' => 'Aviso: encerrados amanhã',
            'caption' => "Aviso: amanhã, sexta-feira, 23 de outubro, a NEXUS estará encerrada.\nRetomamos o funcionamento normal na segunda-feira. Obrigado pela compreensão!\n#Nexus #Aviso",
            'image_path' => 'imagens-posts/post-nexus-fechado.png',
            'image_alt' => 'Post verde com o texto Amanhã estamos fechados, sexta-feira 23 de outubro',
            'networks' => ['Instagram', 'Facebook'],
            'scheduled_at' => Carbon::parse('2026-10-22 18:00'),
            'status' => Post::PENDING,
            'source' => 'Pedido por si: "post a dizer que a empresa está fechada amanhã"',
        ]);

        Post::create([
            'title' => 'Dica: o seu backup está mesmo seguro?',
            'caption' => "Se amanhã perdesse todos os dados da sua empresa, conseguia recuperá-los?\nA regra 3-2-1 é a base de qualquer estratégia de backup: 3 cópias, 2 suportes diferentes, 1 fora da empresa.\nNa NEXUS ajudamos a desenhar a solução certa para o seu negócio: nexus-solutions.pt\n#backup #cibersegurança #disasterrecovery #empresas",
            'image_path' => 'imagens-posts/post-nexus-backup.png',
            'image_alt' => 'Post da NEXUS sobre backup com escudo e cadeado e a regra 3-2-1',
            'networks' => ['Instagram', 'Facebook', 'LinkedIn'],
            'scheduled_at' => Carbon::parse('2026-10-26 10:00'),
            'status' => Post::PENDING,
            'source' => 'Tema escolhido pelo Claude a partir dos serviços NEXUS (Backup e Disaster Recovery)',
        ]);

        // Ideias para os próximos dias.
        foreach ([
            ['Como escolher uma workstation para CAD', '2026-10-26 15:00', Post::IDEA, ['Instagram', 'LinkedIn']],
            ['Supermicro e NVIDIA Vera Rubin', '2026-10-28 10:00', Post::CREATING, ['Instagram', 'Facebook']],
            ['Reel: a regra 3-2-1 em 15 segundos', '2026-10-30 10:00', Post::IDEA, ['Instagram']],
        ] as [$title, $when, $status, $networks]) {
            Post::create([
                'title' => $title,
                'networks' => $networks,
                'scheduled_at' => Carbon::parse($when),
                'status' => $status,
            ]);
        }
    }
}
