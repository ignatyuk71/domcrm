<?php

namespace App\Services\Ai\Gpt;

/** Змінює лише формат промпту й схем інструментів для Responses API. */
class CatalogProtocol
{
    public function __construct(private PromptBuilder $prompt) {}

    public function instructions(?int $connectionId = null): string
    {
        return implode("\n\n", array_column($this->prompt->buildSystemPrompt($connectionId), 'text'));
    }

    public function tools(): array
    {
        return array_map(fn ($tool) => [
            'type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'],
            'strict' => true, 'parameters' => $this->strictSchema($tool['input_schema']),
        ], $this->prompt->toolDefinitions());
    }

    /** У strict-схемі необов’язкові поля позначаються через null. */
    private function strictSchema(array $schema): array
    {
        if (in_array('object', (array) ($schema['type'] ?? []), true)) {
            $required = $schema['required'] ?? [];
            $properties = [];
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                $property = $this->strictSchema($property);
                if (! in_array($name, $required, true)) {
                    $property['type'] = array_values(array_unique([...(array) $property['type'], 'null']));
                }
                $properties[$name] = $property;
            }
            $schema['properties'] = $properties ?: (object) [];
            $schema['required'] = array_keys($properties);
            $schema['additionalProperties'] = false;
        } elseif (in_array('array', (array) ($schema['type'] ?? []), true)) {
            $schema['items'] = $this->strictSchema($schema['items']);
        }

        return $schema;
    }
}
