<?php

namespace Stanford\SecureChatAI;

/**
 * Value object representing a single tool invocation from the LLM.
 */
class ToolUse
{
    public readonly string $name;
    public readonly array $input;
    public readonly string $toolUseId;

    public function __construct(string $name, array $input, string $toolUseId = '')
    {
        $this->name = $name;
        $this->input = $input;
        $this->toolUseId = $toolUseId ?: bin2hex(random_bytes(8));
    }
}
