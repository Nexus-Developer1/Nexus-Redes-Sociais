<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UtilizadoresTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_mudar_a_propria_palavra_passe(): void
    {
        $user = User::factory()->create(['password' => 'antiga-12345']);
        $this->actingAs($user)->get('/conta')->assertOk()->assertSee('Mudar a palavra-passe');

        $this->actingAs($user)->post('/conta/palavra-passe', [
            'current_password' => 'errada', 'password' => 'nova-pass-123', 'password_confirmation' => 'nova-pass-123',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->post('/conta/palavra-passe', [
            'current_password' => 'antiga-12345', 'password' => 'nova-pass-123', 'password_confirmation' => 'nova-pass-123',
        ])->assertSessionHas('ok');
        $this->assertTrue(Hash::check('nova-pass-123', $user->fresh()->password));
    }

    public function test_admin_adiciona_pessoa_e_ve_a_palavra_passe_uma_vez(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/utilizadores', ['name' => 'Ana', 'email' => 'ana@nexus-solutions.pt'])
            ->assertSessionHas('password');

        $ana = User::firstWhere('email', 'ana@nexus-solutions.pt');
        $this->assertFalse($ana->is_admin);
        [$email, $pass] = session('password');
        $this->assertTrue(Hash::check($pass, $ana->password));

        // A Ana entra com a palavra-passe temporária.
        $this->post('/sair');
        $this->post('/entrar', ['email' => $email, 'password' => $pass])->assertRedirect('/');
    }

    public function test_quem_nao_e_admin_nao_gere_utilizadores(): void
    {
        $user = User::factory()->create();
        $outro = User::factory()->create();
        $this->actingAs($user)->get('/utilizadores')->assertForbidden();
        $this->actingAs($user)->post("/utilizadores/{$outro->id}/remover")->assertForbidden();
        $this->actingAs($user)->get('/')->assertDontSee('Utilizadores');
    }

    public function test_fica_sempre_um_admin_e_ninguem_se_remove_a_si(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post("/utilizadores/{$admin->id}/admin")->assertSessionHas('erro');
        $this->actingAs($admin)->post("/utilizadores/{$admin->id}/remover")->assertSessionHas('erro');
        $this->assertTrue($admin->fresh()->is_admin);

        $outro = User::factory()->create();
        $this->actingAs($admin)->post("/utilizadores/{$outro->id}/remover")->assertSessionHas('ok');
        $this->assertNull($outro->fresh());
    }

    public function test_repor_palavra_passe(): void
    {
        $admin = $this->admin();
        $esqueceu = User::factory()->create(['password' => 'esquecida-123']);

        $this->actingAs($admin)->post("/utilizadores/{$esqueceu->id}/palavra-passe")->assertSessionHas('password');

        $this->assertFalse(Hash::check('esquecida-123', $esqueceu->fresh()->password));
        $this->assertTrue(Hash::check(session('password')[1], $esqueceu->fresh()->password));
    }
}
