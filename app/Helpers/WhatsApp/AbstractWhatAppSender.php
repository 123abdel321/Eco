<?php

namespace App\Helpers\WhatsApp;

use Illuminate\Support\Facades\Http;
//MODELS
use App\Models\Empresa\Empresa;

abstract class AbstractWhatAppSender
{
    public abstract function getEndpoint(): string;
    public abstract function getMethod(): string;
    public abstract function getParams(): array;

    public function send($id_empresa)
    {
        // $empresa = Empresa::find($id_empresa);
        
        $url = $this->getUrl();
        $method = $this->getMethod();
        $params = $this->getParams();

        $dataResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
                'Authorization' => "Bearer {$this->getApiToken()}",
            ])
            ->timeout(60);

        switch ($method) {//TIPOS DE METODOS
            case 'GET':
                $dataResponse = $dataResponse->get($url, $params);
                break;
            case 'POST':
                $dataResponse = $dataResponse->post($url, $params);
                break;
            case 'PUT':
                $dataResponse = $dataResponse->put($url, $params);
                break;
            case 'DELETE':
                $dataResponse = $dataResponse->delete($url, $params);
                break;        
        }
        
        $response = (object) $dataResponse->json();
        
        return [
			"status" => $dataResponse->status(),
			"response" => $response,
		];
    }

    protected function getApiToken(): string
    {
        return env('META_WHATSAPP_API_TOKEN');
    }

    protected function getApiVersion(): string
    {
        return env('META_WHATSAPP_API_VERSION');
    }

    protected function getPhoneNumberId(): string
    {
        return env('META_WHATSAPP_PHONE_NUMBER_ID');
    }

    protected function getUrl(): string
	{
        return sprintf(
            "https://graph.facebook.com/%s/%s/%s",
            $this->getApiVersion(),
            $this->getPhoneNumberId(),
            $this->getEndpoint()
        );
	}
}
