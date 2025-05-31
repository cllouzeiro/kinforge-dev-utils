<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Models\Bairro;
use App\Models\Cidades;

class PopulateBairrosCommand extends Command
{
    protected $signature = 'bairros:populate {--estado= : Sigla do estado (ex: SP)}';
    protected $description = 'Popula a tabela de bairros com dados da API do IBGE';

    private $maxRetries = 3;
    private $retryDelay = 2;

    public function handle()
    {
        $estado = $this->option('estado');
        
        if (!$estado) {
            $this->error('Por favor, forneça a sigla do estado usando --estado=XX');
            return 1;
        }

        $estado = strtoupper($estado);
        
        $this->info("Iniciando busca de bairros para o estado: {$estado}");
        
        try {
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
                $this->processCidade($cidade, $estado);
                $bar->advance();
                sleep(1);
            }

            $bar->finish();
            $this->newLine();
            $this->info('Bairros populados com sucesso!');
            
        } catch (\Exception $e) {
            $this->error("Erro ao popular bairros: " . $e->getMessage());
            Log::error("Erro ao popular bairros: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function processCidade($cidade, $estado)
    {
        $retries = 0;
        $success = false;

        while (!$success && $retries < $this->maxRetries) {
            try {
                $bairrosResponse = Http::timeout(30)
                    ->get("https://servicodados.ibge.gov.br/api/v1/localidades/municipios/{$cidade['id']}/distritos");
                
                if ($bairrosResponse->successful()) {
                    $bairros = $bairrosResponse->json();
                    
                    foreach ($bairros as $bairro) {
                        $this->saveBairro($bairro, $cidade, $estado);
                    }
                    $success = true;
                } else {
                    throw new \Exception("Erro na resposta da API: " . $bairrosResponse->status());
                }
            } catch (\Exception $e) {
                $retries++;
                if ($retries < $this->maxRetries) {
                    $this->warn("Tentativa {$retries} falhou para cidade {$cidade['nome']}. Aguardando {$this->retryDelay} segundos...");
                    sleep($this->retryDelay);
                } else {
                    $this->error("Falha ao processar cidade {$cidade['nome']} após {$this->maxRetries} tentativas");
                    Log::error("Falha ao processar cidade {$cidade['nome']}: " . $e->getMessage());
                }
            }
        }
    }

    private function saveBairro($bairro, $cidade, $estado)
    {
        $existingBairro = Bairro::where('nome', $bairro['nome'])
            ->where('municipio', $cidade['nome'])
            ->where('uf', $estado)
            ->first();

        if (!$existingBairro) {
            Bairro::create([
                'nome' => $bairro['nome'],
                'municipio' => $cidade['nome'],
                'codigo_ibge' => $cidade['id'],
                'uf' => $estado
            ]);
        }
    }
} 
