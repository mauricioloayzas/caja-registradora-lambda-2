<?php

namespace LibreriasSri;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service Lecturas
 *
 * Habla directo por HTTP con los webservices SOAP 1.1 del SRI (recepción y
 * autorización) en lugar de usar ext-soap: el runtime PHP de Bref no trae esa
 * extensión y no hay una layer publicada para ella, así que se arma/parsea el
 * sobre SOAP a mano con Guzzle + DOM, que sí están disponibles.
 */
class FacturacionElectronicaService
{
/**
 * url base del servicio a consumir
 */
  public $baseUrl;
  private $cafile;
  private $soapTimeout;
  private ?Client $httpClient = null;
  private $url_sri_valida_pruebas ="https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl";
  private $url_sri_autoriza_pruebas ="https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl";

  private $url_sri_valida_produccion ="https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl";
  private $url_sri_autoriza_produccion ="https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl";

/**
 * key autentication service
 */
  public $secret;

  public function __construct($cafile = null, $soapTimeout = 30)
  {
      $this->baseUrl = null;
      $this->secret = null;
      $this->cafile = $cafile;
      $this->soapTimeout = (int) $soapTimeout;
  }

  /**
   *
   */
  public function enviarComprobanteSri($xml, $ambiente)
  {
      $wsdl     = $this->wsdlValidacion($ambiente);
      $envelope = $this->buildRecepcionEnvelope(base64_encode($xml));
      $doc      = $this->callSoap($wsdl, $envelope);

      $xpath     = new \DOMXPath($doc);
      $respuesta = $this->firstByLocalName($xpath, 'RespuestaRecepcionComprobante', $doc);

      if (!$respuesta) {
          throw new \RuntimeException('No se pudo interpretar la respuesta del SRI (validarComprobante)');
      }

      return json_encode($this->parseRespuestaSolicitud($xpath, $respuesta));
  }

   /**
    *
   */
   public function autorizarComprobanteSri($claveAcceso, $ambiente)
   {
      $wsdl     = $this->wsdlAutorizacion($ambiente);
      $envelope = $this->buildAutorizacionEnvelope($claveAcceso);
      $doc      = $this->callSoap($wsdl, $envelope);

      $xpath     = new \DOMXPath($doc);
      $respuesta = $this->firstByLocalName($xpath, 'RespuestaAutorizacionComprobante', $doc);

      if (!$respuesta) {
          throw new \RuntimeException('No se pudo interpretar la respuesta del SRI (autorizacionComprobante)');
      }

      return json_encode($this->parseRespuestaAutorizacion($xpath, $respuesta));
  }

  private function wsdlValidacion($ambiente): string
  {
      if ($ambiente === '1') {
          return $this->url_sri_valida_pruebas;
      }
      if ($ambiente === '2') {
          return $this->url_sri_valida_produccion;
      }

      throw new \InvalidArgumentException("Ambiente SRI no soportado: {$ambiente}");
  }

  private function wsdlAutorizacion($ambiente): string
  {
      if ($ambiente === '1') {
          return $this->url_sri_autoriza_pruebas;
      }
      if ($ambiente === '2') {
          return $this->url_sri_autoriza_produccion;
      }

      throw new \InvalidArgumentException("Ambiente SRI no soportado: {$ambiente}");
  }

  private function buildRecepcionEnvelope(string $xmlBase64): string
  {
      return '<?xml version="1.0" encoding="UTF-8"?>'
          . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.recepcion">'
          . '<soapenv:Header/>'
          . '<soapenv:Body>'
          . '<ec:validarComprobante>'
          . '<xml>' . $xmlBase64 . '</xml>'
          . '</ec:validarComprobante>'
          . '</soapenv:Body>'
          . '</soapenv:Envelope>';
  }

  private function buildAutorizacionEnvelope(string $claveAcceso): string
  {
      return '<?xml version="1.0" encoding="UTF-8"?>'
          . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">'
          . '<soapenv:Header/>'
          . '<soapenv:Body>'
          . '<ec:autorizacionComprobante>'
          . '<claveAccesoComprobante>' . htmlspecialchars($claveAcceso, ENT_XML1) . '</claveAccesoComprobante>'
          . '</ec:autorizacionComprobante>'
          . '</soapenv:Body>'
          . '</soapenv:Envelope>';
  }

  private function callSoap(string $wsdlUrl, string $envelope): \DOMDocument
  {
      $endpoint = preg_replace('/\?wsdl$/i', '', $wsdlUrl);

      $options = [
          'body'            => $envelope,
          'headers'         => [
              'Content-Type' => 'text/xml; charset=utf-8',
              'SOAPAction'   => '""',
          ],
          'timeout'         => $this->soapTimeout * 2,
          'connect_timeout' => $this->soapTimeout,
          'verify'          => $this->sslCaFile() ?: true,
      ];

      try {
          $response = $this->httpClient()->post($endpoint, $options);
      } catch (GuzzleException $e) {
          throw new \RuntimeException('Error de comunicación con el SRI: ' . $e->getMessage(), 0, $e);
      }

      $body = (string) $response->getBody();

      $previous = libxml_use_internal_errors(true);
      $doc      = new \DOMDocument();
      $loaded   = $doc->loadXML($body);
      libxml_use_internal_errors($previous);

      if (!$loaded) {
          throw new \RuntimeException('Respuesta del SRI no es un XML válido');
      }

      $xpath      = new \DOMXPath($doc);
      $faultNodes = $xpath->query("//*[local-name()='Fault']");

      if ($faultNodes->length > 0) {
          $faultStringNodes = $xpath->query(".//*[local-name()='faultstring']", $faultNodes->item(0));
          $message          = $faultStringNodes->length > 0 ? $faultStringNodes->item(0)->textContent : 'SOAP Fault desconocido';

          throw new \RuntimeException('SOAP Fault del SRI: ' . $message);
      }

      return $doc;
  }

  private function parseRespuestaSolicitud(\DOMXPath $xpath, \DOMElement $respuesta): array
  {
      $data = ['estado' => $this->childText($xpath, $respuesta, 'estado')];

      $comprobanteNodes = $xpath->query(".//*[local-name()='comprobantes']/*[local-name()='comprobante']", $respuesta);

      if ($comprobanteNodes->length > 0) {
          $comprobantes = [];

          foreach ($comprobanteNodes as $comprobanteNode) {
              $comprobantes[] = [
                  'claveAcceso' => $this->childText($xpath, $comprobanteNode, 'claveAcceso'),
                  'mensajes'    => ['mensaje' => $this->parseMensajes($xpath, $comprobanteNode)],
              ];
          }

          $data['comprobantes'] = ['comprobante' => $comprobantes];
      }

      return $data;
  }

  private function parseRespuestaAutorizacion(\DOMXPath $xpath, \DOMElement $respuesta): array
  {
      $autorizacionNodes = $xpath->query(".//*[local-name()='autorizaciones']/*[local-name()='autorizacion']", $respuesta);

      $autorizaciones = [];

      foreach ($autorizacionNodes as $autorizacionNode) {
          $autorizaciones[] = [
              'estado'             => $this->childText($xpath, $autorizacionNode, 'estado'),
              'numeroAutorizacion' => $this->childText($xpath, $autorizacionNode, 'numeroAutorizacion'),
              'fechaAutorizacion'  => $this->childText($xpath, $autorizacionNode, 'fechaAutorizacion'),
              'ambiente'           => $this->childText($xpath, $autorizacionNode, 'ambiente'),
              'comprobante'        => $this->childText($xpath, $autorizacionNode, 'comprobante'),
              'mensajes'           => ['mensaje' => $this->parseMensajes($xpath, $autorizacionNode)],
          ];
      }

      return [
          'claveAccesoConsultada' => $this->childText($xpath, $respuesta, 'claveAccesoConsultada'),
          'numeroComprobantes'    => $this->childText($xpath, $respuesta, 'numeroComprobantes'),
          'autorizaciones'        => ['autorizacion' => $autorizaciones],
      ];
  }

  private function parseMensajes(\DOMXPath $xpath, \DOMNode $context): array
  {
      $mensajeNodes = $xpath->query(".//*[local-name()='mensajes']/*[local-name()='mensaje']", $context);
      $mensajes     = [];

      foreach ($mensajeNodes as $mensajeNode) {
          $mensajes[] = [
              'identificador'        => $this->childText($xpath, $mensajeNode, 'identificador'),
              'mensaje'              => $this->childText($xpath, $mensajeNode, 'mensaje'),
              'informacionAdicional' => $this->childText($xpath, $mensajeNode, 'informacionAdicional'),
              'tipo'                 => $this->childText($xpath, $mensajeNode, 'tipo'),
          ];
      }

      return $mensajes;
  }

  private function firstByLocalName(\DOMXPath $xpath, string $localName, \DOMNode $context): ?\DOMElement
  {
      $nodes = $xpath->query(".//*[local-name()='{$localName}']", $context);

      return $nodes->length > 0 ? $nodes->item(0) : null;
  }

  private function childText(\DOMXPath $xpath, \DOMNode $context, string $localName): ?string
  {
      $nodes = $xpath->query("./*[local-name()='{$localName}']", $context);

      if ($nodes->length === 0) {
          return null;
      }

      $text = trim($nodes->item(0)->textContent);

      return $text === '' ? null : $text;
  }

  private function httpClient(): Client
  {
      if (!$this->httpClient) {
          $this->httpClient = new Client();
      }

      return $this->httpClient;
  }

  private function sslCaFile()
  {
      $candidates = [
          $this->cafile,
          ini_get('openssl.cafile'),
          'C:\laragon\etc\ssl\cacert.pem',
          'C:\laragon\bin\git\mingw64\ssl\cert.pem',
          'C:\laragon\bin\git\usr\ssl\cert.pem',
      ];

      foreach ($candidates as $candidate) {
          if ($candidate && is_file($candidate)) {
              return $candidate;
          }
      }

      return null;
  }
}
