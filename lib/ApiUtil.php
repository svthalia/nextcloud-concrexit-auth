<?php
namespace OCA\ConcrexitAuth;

class ApiUtil {
    public static function doRequest($host, $path, $headers = array(), $body = null) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $host. "/api/v1/" . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch,  CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return array(
            'status' => $status,
            'response' => $response
        );
    }
}