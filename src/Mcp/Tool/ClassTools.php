<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\ClassDefinition\Service as ClassService;

/**
 * Data object class (schema) tools.
 */
final class ClassTools
{
    /** Field types the create/add tools accept, mapped to Pimcore field types. */
    private const FIELD_TYPES = [
        'input' => 'input',
        'textarea' => 'textarea',
        'wysiwyg' => 'wysiwyg',
        'numeric' => 'numeric',
        'checkbox' => 'checkbox',
        'select' => 'select',
        'multiselect' => 'multiselect',
        'date' => 'date',
        'datetime' => 'datetime',
        'email' => 'email',
        'slider' => 'slider',
    ];

    private const FIELD_SCHEMA = [
        'type' => 'array',
        'description' => 'Fields to add.',
        'items' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Field name, camelCase, starts with a letter (e.g. "sku", "targetTemperature").'],
                'title' => ['type' => 'string', 'description' => 'Label shown in Studio. Defaults to the name.'],
                'type' => ['type' => 'string', 'enum' => ['input', 'textarea', 'wysiwyg', 'numeric', 'checkbox', 'select', 'multiselect', 'date', 'datetime', 'email', 'slider']],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Choices for select / multiselect.'],
                'mandatory' => ['type' => 'boolean'],
                'decimalPrecision' => ['type' => 'integer', 'description' => 'numeric only: digits after the decimal point.'],
                'tooltip' => ['type' => 'string'],
            ],
            'required' => ['name', 'type'],
        ],
        'minItems' => 1,
    ];

    public function __construct(private readonly McpContext $ctx)
    {
    }

    #[McpTool(
        name: 'pimcore_list_classes',
        title: 'List data object classes',
        description: 'Lists all data object classes (product types etc.) with their field names.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function listClasses(): CallToolResult
    {
        $this->ctx->user();
        $out = [];
        foreach ((new ClassDefinition\Listing())->load() as $class) {
            $out[] = [
                'id' => $class->getId(),
                'name' => $class->getName(),
                'title' => $class->getTitle(),
                'fields' => array_keys($class->getFieldDefinitions()),
            ];
        }

        return McpContext::text($out);
    }

    #[McpTool(
        name: 'pimcore_get_class',
        title: 'Get class definition',
        description: 'Returns the fields of a data object class: name, type, title, mandatory and options.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getClass(
        #[Schema(description: 'Class name or id.')]
        string $class,
    ): CallToolResult {
        $this->ctx->user();
        $definition = $this->load($class);
        $fields = [];
        foreach ($definition->getFieldDefinitions() as $name => $fd) {
            $field = [
                'name' => $name,
                'type' => $fd->getFieldtype(),
                'title' => $fd->getTitle(),
                'mandatory' => $fd->getMandatory(),
            ];
            if (method_exists($fd, 'getOptions') && is_array($fd->getOptions())) {
                $field['options'] = array_column($fd->getOptions(), 'value');
            }
            $fields[] = $field;
        }

        return McpContext::text([
            'id' => $definition->getId(),
            'name' => $definition->getName(),
            'title' => $definition->getTitle(),
            'allowVariants' => $definition->getAllowVariants(),
            'fields' => $fields,
        ]);
    }

    #[McpTool(
        name: 'pimcore_create_class',
        title: 'Create data object class',
        description: 'Creates a new data object class with the given fields. The class name must start with an upper-case letter and contain only letters and digits (e.g. "Thermostat").',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function createClass(
        #[Schema(description: 'Class name, e.g. "Thermostat".', pattern: '^[A-Z][A-Za-z0-9]*$')]
        string $name,
        #[Schema(definition: self::FIELD_SCHEMA)]
        array $fields,
        #[Schema(description: 'Optional human readable title.')]
        ?string $title = null,
    ): CallToolResult {
        $this->ctx->assertUserPermission('classes');
        if (ClassDefinition::getByName($name) || ClassDefinition::getById($name)) {
            throw new ToolCallException(sprintf('Class "%s" already exists. Use pimcore_add_class_fields to extend it.', $name));
        }

        $layout = ClassService::generateLayoutTreeFromArray([
            'name' => 'pimcore_root',
            'datatype' => 'layout',
            'fieldtype' => 'panel',
            'children' => [[
                'name' => 'Layout',
                'datatype' => 'layout',
                'fieldtype' => 'panel',
                'title' => '',
                'labelWidth' => 150,
                'children' => array_map(fn (array $f): array => $this->fieldArray($f), $fields),
            ]],
        ], true);

        $class = ClassDefinition::create([
            'name' => $name,
            'title' => $title ?? '',
            'userOwner' => $this->ctx->user()->getId(),
            'userModification' => $this->ctx->user()->getId(),
        ]);
        $class->setId($name);
        $class->setLayoutDefinitions($layout);
        $class->save();

        return $this->getClass($class->getName());
    }

    #[McpTool(
        name: 'pimcore_add_class_fields',
        title: 'Add fields to a class',
        description: 'Appends new fields to an existing data object class. Existing fields and data are kept.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function addClassFields(
        #[Schema(description: 'Class name or id.')]
        string $class,
        #[Schema(definition: self::FIELD_SCHEMA)]
        array $fields,
    ): CallToolResult {
        $this->ctx->assertUserPermission('classes');
        $definition = $this->load($class);
        $existing = $definition->getFieldDefinitions();

        $root = $definition->getLayoutDefinitions();
        $container = $root;
        foreach ($root?->getChildren() ?? [] as $child) {
            if ($child instanceof Layout) {
                $container = $child;
                break;
            }
        }
        if (!$container instanceof Layout) {
            throw new ToolCallException('The class has no layout to add fields to.');
        }

        foreach ($fields as $field) {
            if (isset($existing[$field['name'] ?? ''])) {
                throw new ToolCallException(sprintf('Field "%s" already exists.', $field['name']));
            }
            $data = ClassService::generateLayoutTreeFromArray($this->fieldArray($field), true);
            if (!$data instanceof Data) {
                throw new ToolCallException(sprintf('Could not build field "%s".', $field['name'] ?? '?'));
            }
            $container->addChild($data);
        }

        $definition->setUserModification($this->ctx->user()->getId());
        $definition->save();

        return $this->getClass($definition->getName());
    }

    private function load(string $class): ClassDefinition
    {
        $definition = ClassDefinition::getByName($class) ?? ClassDefinition::getById($class);
        if (!$definition) {
            throw new ToolCallException(sprintf('Class "%s" not found.', $class));
        }

        return $definition;
    }

    private function fieldArray(array $field): array
    {
        $name = (string) ($field['name'] ?? '');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new ToolCallException(sprintf('Invalid field name "%s".', $name));
        }
        $type = self::FIELD_TYPES[$field['type'] ?? ''] ?? null;
        if ($type === null) {
            throw new ToolCallException(sprintf('Unsupported field type "%s" for "%s".', $field['type'] ?? '', $name));
        }

        $array = [
            'name' => $name,
            'title' => (string) ($field['title'] ?? $name),
            'datatype' => 'data',
            'fieldtype' => $type,
            'mandatory' => (bool) ($field['mandatory'] ?? false),
            'tooltip' => (string) ($field['tooltip'] ?? ''),
            'noteditable' => false,
            'index' => false,
            'locked' => false,
            'invisible' => false,
            'visibleGridView' => true,
            'visibleSearch' => true,
        ];

        if (in_array($type, ['select', 'multiselect'], true)) {
            $options = $field['options'] ?? [];
            if ($options === []) {
                throw new ToolCallException(sprintf('Field "%s" needs options.', $name));
            }
            $array['options'] = array_map(
                static fn ($o): array => ['key' => (string) $o, 'value' => (string) $o],
                array_values($options)
            );
        }
        if ($type === 'numeric' && isset($field['decimalPrecision'])) {
            $array['decimalPrecision'] = (int) $field['decimalPrecision'];
        }
        if ($type === 'input') {
            $array['columnLength'] = 190;
        }

        return $array;
    }
}
