<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Models\Cidades;

class CidadesCommand extends Command
{
    protected $signature = 'app:cidades {--estado= : Sigla do estado (ex: SP)}';

    protected $description = 'Cadastra cidades no sistema';

    public function handle()
    {
        $estado = $this->option('estado');
        
        if (!$estado) {
            $this->error('Por favor, forneça a sigla do estado usando --estado=XX');
            return 1;
        }

        $estado = strtoupper($estado);
        
        $this->info("Iniciando busca de cidades para o estado: {$estado}");

        $response = Http::timeout(30)->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$estado}/municipios");
            
        if (!$response->successful()) {
            $this->error('Erro ao buscar cidades do estado');
            return 1;
        }

        $cidades = $response->json();
        $totalCidades = count($cidades);
        $this->info("Encontradas {$totalCidades} cidades");

        $bar = $this->output->createProgressBar($totalCidades);
        $bar->start();
        
        foreach ($cidades as $cidade) {
            $existingCidade = Cidades::where('cod_ibge',$cidade['id'])->first();

            if(!$existingCidade)
            {
                Cidades::create([
                    'nome' => $cidade['nome'],
                    'uf' => 'MG',
                    'cod_ibge' => $cidade['id'],
                ]);
            }

            $bar->advance();
            sleep(1);
        }

        $bar->finish();
        $this->newLine();
        $this->info('Bairros populados com sucesso!');
    }
}
