<?php

namespace Stanford\SecureChatAI;

/**
 * Value object representing the result of a tool execution.
 */
class ToolResult
{
    public readonly mixed $data;
    public readonly bool $isError;
    public readonly ?string $errorMessage;

    public function __construct(mixed $data, bool $isError = false, ?string $errorMessage = null)
    {
        $this->data = $data;
        $this->isError = $isError;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Convert to the array format used by the existing agent loop
     * (backward-compatible with executeToolCall() return shape).
     */
    public function toArray(): array
    {
        if ($this->isError) {
            return [
                'error'   => true,
                'type'    => 'TOOL_ERROR',
                'message' => $this->errorMessage ?? 'Tool execution failed',
            ];
        }

        return [
            'error'  => false,
            'result' => $this->data,
        ];
    }

    /** Convenience factory for success */
    public static function ok(mixed $data): self
    {
        return new self($data);
    }

    /** Convenience factory for error */
    public static function fail(string $message, mixed $data = null): self
    {
        return new self($data, true, $message);
    }
}
