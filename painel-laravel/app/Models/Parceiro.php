<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Parceiro que recebe por email os posts marcados com "Enviar também aos parceiros". */
class Parceiro extends Model
{
    protected $table = 'parceiros';

    protected $fillable = ['nome', 'empresa', 'email', 'ativo', 'saiu_em'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'saiu_em' => 'datetime'];
    }

    public function envios(): HasMany
    {
        return $this->hasMany(ParceiroEnvio::class);
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('ativo', true);
    }

    /** Quantos parceiros recebem hoje (uma vez por pedido: aparece em vários cartões). */
    public static function quantosAtivos(): int
    {
        return once(fn () => self::ativos()->count());
    }

    /**
     * Lê uma lista colada do Excel ou um CSV: uma pessoa por linha, com o email e, se houver, o nome e a empresa
     * (separados por tabulação, ponto e vírgula ou vírgula, por qualquer ordem). Linhas sem email são ignoradas
     * (ex.: o cabeçalho). Devolve [['nome' =>, 'empresa' =>, 'email' =>], ...] sem emails repetidos.
     *
     * @return array{0: array<int, array{nome: string, empresa: ?string, email: string}>, 1: int}  [parceiros, linhas ignoradas]
     */
    public static function lerLista(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto); // BOM do Excel
        $lista = [];
        $ignoradas = 0;
        foreach (preg_split('/\R/u', $texto) as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            // Colado do Excel: só as tabulações separam (uma empresa "InfoPorto, Lda." não se parte);
            // num CSV, o ponto e vírgula do Excel português ou, sem ele, a vírgula.
            $separador = str_contains($linha, "\t") ? '/\t/' : (str_contains($linha, ';') ? '/;/' : '/,/');
            $partes = array_values(array_filter(array_map(fn ($p) => trim($p, " \t\"'<>"), preg_split($separador, $linha)), fn ($p) => $p !== ''));
            $email = null;
            $outros = [];
            foreach ($partes as $p) {
                // "Ana Costa <ana@empresa.pt>" numa só coluna
                if (! $email && preg_match('/[^\s<>"]+@[^\s<>"]+/', $p, $m) && filter_var($m[0], FILTER_VALIDATE_EMAIL)) {
                    $email = mb_strtolower($m[0]);
                    $resto = trim(str_replace($m[0], '', $p), " \t\"'<>");
                    if ($resto !== '') {
                        $outros[] = $resto;
                    }
                } else {
                    $outros[] = $p;
                }
            }
            if (! $email) {
                $ignoradas++;

                continue;
            }
            $lista[$email] ??= [
                'nome' => mb_substr($outros[0] ?? ucfirst(strtok($email, '@')), 0, 150),
                'empresa' => isset($outros[1]) ? mb_substr($outros[1], 0, 150) : null,
                'email' => $email,
            ];
        }

        return [array_values($lista), $ignoradas];
    }
}
