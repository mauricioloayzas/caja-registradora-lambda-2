<?php

namespace App\Common\Services;

use LibreriasSri\FacturacionElectronicaLibrary;

class SriService
{
    private FacturacionElectronicaLibrary $sri;

    public function __construct()
    {
        $this->sri = new FacturacionElectronicaLibrary();
    }

    public function generarXml(string $tipoComprobante, array $data): string
    {
        return $this->sri->generarXml($tipoComprobante, $data);
    }

    public function firmarXml(string $tipoComprobante, string $xml, string $certificadoP12Base64, string $claveCertificado): string
    {
        $certificadoP12 = base64_decode($certificadoP12Base64);
        return $this->sri->firmarXml($tipoComprobante, $xml, $certificadoP12, $claveCertificado);
    }

    public function generarYFirmarXml(string $tipoComprobante, array $data, string $certificadoP12Base64, string $claveCertificado): string
    {
        $certificadoP12 = base64_decode($certificadoP12Base64);
        return $this->sri->generarYFirmarXml($tipoComprobante, $data, $certificadoP12, $claveCertificado);
    }

    public function enviarSri(string $xmlFirmado, string $ambiente): array
    {
        return $this->sri->enviarSri($xmlFirmado, $ambiente);
    }

    public function autorizarSri(string $claveAcceso, string $ambiente): array
    {
        return $this->sri->autorizarSri($claveAcceso, $ambiente);
    }

    public function generarClaveAcceso(
        string $fechaEmision,
        string $tipoComprobante,
        string $ruc,
        string $ambiente,
        string $serie,
        string $secuencial,
        string $codigoNumerico,
        string $tipoEmision = '1'
    ): string {
        return $this->sri->generarClaveAcceso(
            $fechaEmision,
            $tipoComprobante,
            $ruc,
            $ambiente,
            $serie,
            $secuencial,
            $codigoNumerico,
            $tipoEmision
        );
    }

    public function validarIdentificacion(string $tipo, string $numero): bool
    {
        return $this->sri->validarIdentificacion($tipo, $numero);
    }

    public function extraerClaveAcceso(string $xml): ?string
    {
        if (preg_match('/<claveAcceso>([^<]*)<\/claveAcceso>/', $xml, $matches) && $matches[1] !== '') {
            return $matches[1];
        }

        return null;
    }
}
