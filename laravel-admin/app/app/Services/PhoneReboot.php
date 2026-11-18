<?php

namespace App\Services;

class PhoneReboot
{
    public function send(string $endpoint): bool
    {
        $address = config('phone_provisioning.ami_address');
        $username = (string) config('phone_provisioning.ami_username');
        $secret = (string) config('phone_provisioning.ami_secret');

        if (! $address || $username === '' || $secret === '' || strpbrk($username.$secret.$endpoint, "\r\n\0") !== false) {
            return false;
        }

        $socket = @stream_socket_client($address, $errorCode, $errorMessage, 3);
        if ($socket === false) {
            return false;
        }

        try {
            stream_set_timeout($socket, 3);
            if (! str_starts_with((string) fgets($socket, 1024), 'Asterisk Call Manager/')) {
                return false;
            }

            return $this->action($socket, [
                'Action' => 'Login', 'Username' => $username, 'Secret' => $secret, 'Events' => 'off',
            ]) && $this->action($socket, [
                'Action' => 'PJSIPNotify', 'Endpoint' => $endpoint, 'Variable' => 'Event=check-sync;reboot=true',
            ]);
        } finally {
            fclose($socket);
        }
    }

    private function action($socket, array $headers): bool
    {
        $request = '';
        foreach ($headers as $key => $value) {
            $request .= "$key: $value\r\n";
        }
        $request .= "\r\n";
        if (@fwrite($socket, $request) !== strlen($request)) {
            return false;
        }

        $response = fgets($socket, 1024);
        for ($lines = 0; $lines < 32 && ($line = fgets($socket, 1024)) !== false; $lines++) {
            if ($line === "\r\n") {
                return $response === "Response: Success\r\n";
            }
        }

        return false;
    }
}
