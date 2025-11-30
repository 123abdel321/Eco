<?php

namespace App\Helpers\WhatsApp;

class SendWhatApp extends AbstractWhatAppSender
{
	private $method = 'POST';
	private $language = 'es_ES';
	private $endpoint = 'messages';

	private $to;
	private $template_name;
	private $parameters_body;
	private $parameters_header;
	private $parameters_footer;

	public function __construct(String $template_name, String $to, Array $parameters_header = null, Array $parameters_body = null, String $parameters_footer = null)
	{
		$this->to = $to;
		$this->template_name = $template_name;
		$this->parameters_body = $parameters_body;
		$this->parameters_header = $parameters_header;
		$this->parameters_footer = $parameters_footer;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function getEndpoint(): string
	{
		return $this->endpoint;
	}	

	public function getParams(): array
	{
		return [
            'messaging_product' => 'whatsapp',
            'to' => $this->to,
            'type' => 'template',
            'template' => [
                'name' => $this->template_name,
                'language' => ['code' => $this->language],
                'components' => $this->dataComponent()
            ],
		];
	}

	private function dataComponent(): array
	{
		$dataComponent = [];

		if ($this->parameters_header) {
			$dataComponent[] = [
				'type' => 'HEADER',
				'parameters' => array_map(function ($param) {
					return ['type' => 'text', 'text' => $param];
				}, $this->parameters_header),
			];
		}

		if ($this->parameters_body) {
			$dataComponent[] = [
				'type' => 'BODY',
				'parameters' => array_map(function ($param) {
					return ['type' => 'text', 'text' => $param];
				}, $this->parameters_body),
			];
		}

		if ($this->parameters_footer) {
			$dataComponent[] = [
				'type' => 'FOOTER',
				'text' => $this->parameters_footer
			];
		}

		return $dataComponent;
	}
}
