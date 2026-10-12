<?php

namespace Stanford\SecureChatAI;

/**
 * Raised when a call cannot be served under the token policy it resolved to:
 * an unknown policy value, a request below the project's minimum, or a model
 * alias that is not configured under that policy.
 *
 * These are configuration/caller errors, not transient ones, so callAI()
 * returns them immediately instead of retrying.
 */
class TokenPolicyException extends \RuntimeException
{
}

/**
 * AI Hub token policies.
 *
 * SecureChatAI talks to two sets of AI Hub models, each behind its own
 * subscription key:
 *   - standard: the original models, key in `aihub-token-standard`
 *   - high:     the high-threshold models, key in `aihub-token-high-threshold`
 *
 * The policy is a DATA rule: data a caller marks `high` must only ever reach a
 * high-threshold model. So there is no fallback in either direction, and a
 * project's default policy is a minimum — a call can raise it, never lower it.
 *
 * Framework-free on purpose so it can be unit tested (tests/TokenPolicyTest.php).
 */
final class TokenPolicy
{
    public const STANDARD = 'standard';
    public const HIGH = 'high';

    /** Strictness order. A higher rank may never be served by a lower one. */
    private const RANK = [
        self::STANDARD => 0,
        self::HIGH     => 1,
    ];

    /**
     * Normalize a caller-supplied policy value.
     *
     * @return string|null null when nothing was asked for (null / empty string)
     * @throws TokenPolicyException on any other unrecognized value
     */
    public static function normalize($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new TokenPolicyException("Invalid token_policy: expected 'standard' or 'high'.");
        }
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (!isset(self::RANK[$value])) {
            throw new TokenPolicyException(
                "Invalid token_policy '" . substr($value, 0, 40) . "': expected 'standard' or 'high'."
            );
        }
        return $value;
    }

    /**
     * The stricter of two policies.
     */
    public static function stricter(string $a, string $b): string
    {
        return self::RANK[$a] >= self::RANK[$b] ? $a : $b;
    }

    /**
     * Resolve the policy a call runs under.
     *
     * @param string|null $requested normalized per-call value (null = not specified)
     * @param string      $minimum   the floor: project default and/or the policy
     *                               of an enclosing call
     * @throws TokenPolicyException when the request is below the minimum
     */
    public static function resolve(?string $requested, string $minimum): string
    {
        if ($requested === null) {
            return $minimum;
        }
        if (self::RANK[$requested] < self::RANK[$minimum]) {
            throw new TokenPolicyException(
                "token_policy '{$requested}' is below this context's minimum '{$minimum}'. "
                . "Data under the '{$minimum}' policy may only be sent to '{$minimum}' models."
            );
        }
        return $requested;
    }

    /**
     * Build the model registry from the `api-settings` rows.
     *
     * Rows are keyed by policy and then alias, so a high-threshold row may reuse
     * a standard alias without overwriting it. Each row's token is its own
     * `api-token` when set (legacy override), otherwise the system token for the
     * row's policy. A row with an unrecognized policy is dropped (fail closed).
     *
     * @param array  $rows          getSubSettings('api-settings')
     * @param string $standardToken system setting `aihub-token-standard`
     * @param string $highToken     system setting `aihub-token-high-threshold`
     * @return array<string, array<string, array>> [policy => [alias => config]]
     */
    public static function buildRegistry(array $rows, string $standardToken, string $highToken): array
    {
        $systemTokens = [
            self::STANDARD => trim($standardToken),
            self::HIGH     => trim($highToken),
        ];
        $registry = [self::STANDARD => [], self::HIGH => []];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $alias = trim((string)($row['model-alias'] ?? ''));
            if ($alias === '') {
                continue;
            }
            try {
                $policy = self::normalize($row['token-policy'] ?? null) ?? self::STANDARD;
            } catch (TokenPolicyException $e) {
                continue;
            }

            $rowToken = trim((string)($row['api-token'] ?? ''));

            $registry[$policy][$alias] = [
                'api_url'      => trim((string)($row['api-url'] ?? '')),
                'api_token'    => $rowToken !== '' ? $rowToken : $systemTokens[$policy],
                'api_key_var'  => trim((string)($row['api-key-var'] ?? '')),
                'required'     => $row['api-input-var'] ?? null,
                'model_id'     => trim((string)($row['model-id'] ?? '')),
                'token_policy' => $policy,
                'is_default'   => !empty($row['default-model']),
            ];
        }

        return $registry;
    }

    /**
     * Validate a config-dialog save (shape from ExternalModules::formatRawSettings:
     * repeatable sub-setting keys hold arrays indexed by row).
     *
     * @return string|null error message, or null when the save may proceed
     */
    public static function validateSettings(array $settings): ?string
    {
        if (array_key_exists('token-policy-default', $settings)) {
            try {
                self::normalize($settings['token-policy-default']);
            } catch (TokenPolicyException $e) {
                return "Default token policy must be 'standard' or 'high'.";
            }
        }

        // Only a system-settings save carries the model rows.
        if (!array_key_exists('model-alias', $settings)) {
            return null;
        }

        $aliases  = self::rowValues($settings['model-alias'] ?? null);
        $policies = self::rowValues($settings['token-policy'] ?? null);
        $tokens   = self::rowValues($settings['api-token'] ?? null);
        $systemTokens = [
            self::STANDARD => trim((string)($settings['aihub-token-standard'] ?? '')),
            self::HIGH     => trim((string)($settings['aihub-token-high-threshold'] ?? '')),
        ];
        $labels = [
            self::STANDARD => 'AI Hub Token (standard)',
            self::HIGH     => 'AI Hub Token (high-threshold)',
        ];

        $errors = [];
        foreach ($aliases as $i => $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') {
                continue;
            }
            try {
                $policy = self::normalize($policies[$i] ?? null) ?? self::STANDARD;
            } catch (TokenPolicyException $e) {
                $errors[] = "Model '{$alias}': token policy must be 'standard' or 'high'.";
                continue;
            }
            $rowToken = trim((string)($tokens[$i] ?? ''));
            if ($rowToken === '' && $systemTokens[$policy] === '') {
                $errors[] = "Model '{$alias}' uses the {$policy} policy but has no API token: "
                    . "set '{$labels[$policy]}' or the row's API Token override.";
            }
        }

        return empty($errors) ? null : implode("\n", $errors);
    }

    /**
     * A repeatable sub-setting value as a row-indexed list.
     */
    private static function rowValues($value): array
    {
        if ($value === null) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }
}
