<?php

namespace Stanford\SecureChatAI;


use Exception;

/**
 * Supported Models : gpt-4o , ada-002
 */

abstract class BaseModelRequest implements ModelInterface {
    protected string $apiEndpoint;
    protected string $apiKey;
    protected string $modelId;
    protected array $headers = ['Content-Type: application/json', 'Accept: application/json'];
    protected array $defaultParams = [];
    protected string $auth_key_name;
    protected \Stanford\SecureChatAI\SecureChatAI $module;
    protected string $model;

    public function __construct($module, array $modelConfig, array $defaultParams, string $model) {
        $this->module = $module;
        $this->apiEndpoint = trim($modelConfig['api_url']);
        $this->apiKey = trim($modelConfig['api_token']);
        $this->modelId = trim($modelConfig['model_id']);
        $this->auth_key_name = trim($modelConfig['api_key_var']);
        $this->defaultParams = $defaultParams;
        $this->model = $model;
    }

    public function validateParams(array $params): void {
        try{
            foreach ($this->requiredParams() as $param) {
                if (empty($params[$param])) {
                    throw new Exception("Missing required parameter: $param");
                }
            }
        } catch (\Exception $e) {
            $this->module->emError("$e");
        }
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }


    protected function requiredParams(): array {
        return [];
    }

    /**
     * @throws Exception
     */
    public function executeAPICall(string $apiEndpoint, string|array $params, array $headers = null): string {
        // Sanitize URL: strip any non-printable/invisible characters
        $apiEndpoint = preg_replace('/[^\x20-\x7E]/', '', $apiEndpoint);
        $apiEndpoint = trim($apiEndpoint);

        $this->captureRequest($apiEndpoint, $params, $headers ?? $this->headers);
        $this->logFullRequest($apiEndpoint, $params, $headers ?? $this->headers);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 500);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        // Apply DNS override if configured (for site-to-site VPN routing)
        $dnsOverrideIp = $this->module->getSystemSetting('apim_dns_override_ip');
        if (!empty($dnsOverrideIp)) {
            $host = parse_url($apiEndpoint, PHP_URL_HOST);
            $port = parse_url($apiEndpoint, PHP_URL_PORT) ?: 443;
            if ($host) {
                curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:{$dnsOverrideIp}"]);
            }
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $curlError = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $curlError);
        }

        if ($http_code < 200 || $http_code >= 300) {
            // PHI-safe: do not embed the raw response body in the exception message — it can
            // echo prompt/response content (PHI) into upstream debug logs (see callAI catch).
            $responseLength = strlen((string) $response);
            curl_close($ch);
            throw new Exception('HTTP error: ' . $http_code
                . ' (response body omitted; length=' . $responseLength . ' bytes)');
        }

        curl_close($ch);

        return $response;
    }
    /**
     * Log the whole outgoing request - URL, headers, body - to the emLogger file.
     *
     * This is the "just let me read the payload" view: one JSON line per call in
     * `<base-server-path>/secure_chat_ai.json`, appended before the request goes out, so a call that
     * the provider rejects is logged exactly like one it accepts. `captureRequest()` above is the
     * other half of the pair - it writes replayable per-call files; this writes a tailable stream.
     *
     * **Development servers only.** The body is the prompt, and the prompt is PHI, so the gate is
     * REDCap's own `is_development_server` flag rather than a module setting: there is nothing to
     * remember to switch off, and nothing an operator can switch on by mistake on a production
     * instance. On production this method returns before touching anything.
     *
     * emLog rather than emDebug on purpose. emDebug is gated behind
     * `enable-system-debug-logging`, and turning that on to see a payload also turns on every other
     * emDebug in this module at once - the payload you were looking for then arrives buried in
     * hundreds of unrelated lines. This way the flag stays off and the file stays readable.
     *
     * Requires the `em_logger` module to be enabled system-wide; the trait's helpers are silent
     * no-ops when it is not, so a missing emLogger degrades to "no log", never to an error.
     *
     * The API key is masked in both the URL and the headers, so a line from this file is safe to
     * paste into a ticket. Anything that throws in here is swallowed: a logging aid must never be
     * able to break a call.
     */
    protected function logFullRequest(string $apiEndpoint, string|array $params, array $headers): void
    {
        try {
            if (empty($GLOBALS['is_development_server'])) {
                return;
            }

            $key  = trim($this->apiKey);
            $mask = static fn(string $s): string => $key === '' ? $s : str_replace($key, '${API_KEY}', $s);

            // Decoded when it is JSON, so the payload lands as a nested object in the log line
            // instead of an escaped blob. Whisper/TTS post a multipart array, which is already one.
            $body = is_string($params) ? (json_decode($params, true) ?? $params) : $params;

            // A multipart body can hold a CURLFile, which json_encode either renders as {} or
            // throws on - and a throw here would be swallowed below, silently dropping the whole
            // line. Naming the type keeps the line, and "no log" is the one outcome worth avoiding.
            if (is_array($body)) {
                $body = array_map(
                    static fn($v) => (is_scalar($v) || is_array($v) || $v === null)
                        ? $v
                        : '<' . get_debug_type($v) . '>',
                    $body
                );
            }

            $this->module->emLog('SecureChatAI outgoing request', [
                'model_alias' => $this->model,
                'model_id'    => $this->modelId,
                'method'      => 'POST',
                'url'         => $mask($apiEndpoint),
                'headers'     => array_map(static fn($h): string => $mask((string) $h), $headers),
                'body'        => $body,
            ]);
        } catch (\Throwable $e) {
            // Deliberately silent - see the docblock.
        }
    }

    /**
     * Write the exact outgoing request to disk, as a body file plus a runnable curl script.
     *
     * Off unless somebody deliberately turns it on, because this dumps the request body verbatim and
     * the request body IS the PHI - it is the prompt. The rest of this module is hardened against
     * exactly that (callAI's catch: "Never log $params directly"; the HTTP-error path above omits the
     * response body), so the capture deliberately does NOT go anywhere near emDebug or the REDCap log
     * tables. It goes to a directory an operator has to create by hand.
     *
     * Arming takes two acts, and undoing either one disarms it:
     *   1. point SECURECHAT_CAPTURE_DIR (env) or the `securechat-capture-dir` system setting at a path
     *   2. create that directory and make it writable
     *
     * The API key is never written. Both the URL (legacy query-string auth) and the auth header get
     * the literal key swapped for ${AIHUB_KEY}, which the emitted curl expects in the environment.
     *
     * Coverage: six of the seven model classes route here, including every chat path. The exception
     * is GPT4oMiniTTSModelRequest, which runs its own curl_init and is therefore NOT captured.
     *
     * Anything that goes wrong here is swallowed: a debugging aid must not be able to break a call.
     */
    protected function captureRequest(string $apiEndpoint, string|array $params, array $headers): void
    {
        try {
            $dir = $this->captureDir();
            if ($dir === null) {
                return;
            }

            $stamp = date('Ymd-His') . sprintf('-%03d', (int) round((microtime(true) - (int) microtime(true)) * 1000));
            $base  = $dir . '/' . $stamp . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $this->model);

            // Whisper/TTS post a multipart array rather than a JSON string; record it as JSON and say
            // so, rather than pretending the curl below would reproduce a file upload.
            $isJsonBody = is_string($params);
            $body       = $isJsonBody ? $params : json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $bodyFile   = $base . '.body.json';
            file_put_contents($bodyFile, (string) $body);

            $key      = trim($this->apiKey);
            $mask     = static fn(string $s): string => $key === '' ? $s : str_replace($key, '${AIHUB_KEY}', $s);
            $shQuote  = static fn(string $s): string => '"' . str_replace(['\\', '"', '`', '$('], ['\\\\', '\\"', '\\`', '\\$('], $s) . '"';

            $lines = [
                '#!/bin/sh',
                '# The exact request SecureChatAI sent at ' . date('c') . '.',
                '# model alias: ' . $this->model . '   model_id: ' . $this->modelId,
                '#',
                '# The API key is NOT in this file. Export it first:',
                '#   export AIHUB_KEY="$(mysql ... redcap_external_module_settings -> api-token)"',
                '',
                'set -eu',
                'cd "$(dirname "$0")"',
                '',
            ];

            if (!$isJsonBody) {
                $lines[] = '# NOTE: this call posted multipart/form-data (audio upload), not JSON.';
                $lines[] = '# The body file is a JSON rendering of the form fields, not replayable as-is.';
                $lines[] = '';
            }

            $curl = ['curl -sS -X POST ' . $shQuote($mask($apiEndpoint))];
            foreach ($headers as $header) {
                $curl[] = '  -H ' . $shQuote($mask((string) $header));
            }
            $curl[] = '  --data-binary @' . basename($bodyFile);

            $lines[] = implode(" \\\n", $curl);
            $lines[] = '';

            file_put_contents($base . '.curl.sh', implode("\n", $lines));
            @chmod($base . '.curl.sh', 0755);
        } catch (\Throwable $e) {
            // Deliberately silent - see the docblock.
        }
    }

    /**
     * The armed capture directory, or null when capture is off. See captureRequest().
     */
    private function captureDir(): ?string
    {
        $dir = (string) (getenv('SECURECHAT_CAPTURE_DIR') ?: '');

        if ($dir === '') {
            // A key that is not declared in config.json, so it cannot be flipped on by accident from
            // the module's own settings screen.
            try {
                $dir = (string) ($this->module->getSystemSetting('securechat-capture-dir') ?? '');
            } catch (\Throwable $e) {
                $dir = '';
            }
        }

        $dir = rtrim(trim($dir), '/');

        return ($dir !== '' && is_dir($dir) && is_writable($dir)) ? $dir : null;
    }

    public static function normalizeResponse(array $response): array {
        return $response; // Override in subclasses as needed
    }

    public function getAuthKeyName(): string
    {
        return $this->auth_key_name;
    }

    public function setAuthKeyName(string $auth_key_name): void
    {
        $this->auth_key_name = $auth_key_name;
    }
}
