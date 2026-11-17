<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpContext;
use Carbon\Carbon;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\Element\Recyclebin;
use Pimcore\Model\Element\Service as ElementService;

/**
 * Data object (content) tools: browse, read, create, update, delete.
 */
final class ObjectTools
{
    public function __construct(private readonly McpContext $ctx)
    {
    }

    #[McpTool(
        name: 'pimcore_list_objects',
        title: 'List data objects',
        description: 'Lists data objects and folders under a folder (recursively if requested). Filter by class and/or a text contained in the key.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function listObjects(
        #[Schema(description: 'Folder path, e.g. "/" or "/Products/Thermostats".')]
        string $path = '/',
        #[Schema(description: 'Include all descendants, not only direct children.')]
        bool $recursive = false,
        #[Schema(description: 'Only objects of this class (name).')]
        ?string $class = null,
        #[Schema(description: 'Only elements whose key contains this text.')]
        ?string $search = null,
        #[Schema(minimum: 1, maximum: 500)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): CallToolResult {
        $parent = $this->ctx->folder($path);
        $this->ctx->assertAllowed($parent, 'list');
        $user = $this->ctx->user();

        $listing = new DataObject\Listing();
        $listing->setUnpublished(true);
        $listing->setObjectTypes([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER, DataObject::OBJECT_TYPE_VARIANT]);
        $conditions = [];
        $params = [];
        if ($recursive) {
            $prefix = rtrim($parent->getRealFullPath(), '/') . '/';
            $conditions[] = 'CONCAT(`path`, `key`) LIKE ?';
            $params[] = str_replace(['_', '%'], ['\_', '\%'], $prefix) . '%';
        } else {
            $conditions[] = 'parentId = ?';
            $params[] = $parent->getId();
        }
        if ($class) {
            $definition = ClassDefinition::getByName($class);
            if (!$definition) {
                throw new ToolCallException(sprintf('Class "%s" not found.', $class));
            }
            $conditions[] = 'classId = ?';
            $params[] = $definition->getId();
        }
        if ($search) {
            $conditions[] = '`key` LIKE ?';
            $params[] = '%' . str_replace(['_', '%'], ['\_', '\%'], $search) . '%';
        }
        $listing->setCondition(implode(' AND ', $conditions), $params);
        $listing->setOrderKey(['path', 'key']);
        $listing->setOrder('ASC');

        $total = $listing->getTotalCount();
        $listing->setLimit($limit);
        $listing->setOffset($offset);

        $items = [];
        foreach ($listing->load() as $object) {
            if ($object->isAllowed('list', $user)) {
                $items[] = $this->ctx->summary($object);
            }
        }

        return McpContext::text(['total' => $total, 'offset' => $offset, 'items' => $items]);
    }

    #[McpTool(
        name: 'pimcore_get_object',
        title: 'Get data object',
        description: 'Returns a data object with all field values, by id or full path.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function getObject(
        #[Schema(description: 'Object id (e.g. "42") or full path (e.g. "/Products/T-100").')]
        string $object,
    ): CallToolResult {
        return McpContext::text($this->describe($this->ctx->object($object)));
    }

    #[McpTool(
        name: 'pimcore_create_object',
        title: 'Create data object',
        description: 'Creates a data object of a class under a folder (missing folders are created). Values are keyed by field name; see pimcore_get_class for the fields. Dates as ISO 8601, select as the option value, multiselect as a list.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function createObject(
        #[Schema(description: 'Class name, e.g. "Thermostat".')]
        string $class,
        #[Schema(description: 'Object key (name in the tree).')]
        string $key,
        #[Schema(description: 'Parent folder path, e.g. "/Products/Thermostats".')]
        string $parentPath = '/',
        #[Schema(definition: ['type' => 'object', 'description' => 'Field values keyed by field name.', 'additionalProperties' => true])]
        array $values = [],
        bool $published = false,
    ): CallToolResult {
        $definition = ClassDefinition::getByName($class);
        if (!$definition) {
            throw new ToolCallException(sprintf('Class "%s" not found.', $class));
        }
        $parent = $this->ctx->folder($parentPath, true);
        $this->ctx->assertAllowed($parent, 'create');

        $validKey = ElementService::getValidKey($key, 'object');
        if (DataObject\Service::pathExists(rtrim($parent->getRealFullPath(), '/') . '/' . $validKey)) {
            throw new ToolCallException(sprintf('An element named "%s" already exists in %s.', $validKey, $parent->getRealFullPath()));
        }

        $className = '\\Pimcore\\Model\\DataObject\\' . ucfirst($definition->getName());
        /** @var DataObject\Concrete $object */
        $object = new $className();
        $object->setKey($validKey);
        $object->setParent($parent);
        $object->setUserOwner($this->ctx->user()->getId());
        $object->setUserModification($this->ctx->user()->getId());
        $this->applyValues($object, $values);
        $object->setPublished($published);
        $object->save(['versionNote' => 'Created via MCP']);

        return McpContext::text($this->describe($object));
    }

    #[McpTool(
        name: 'pimcore_update_object',
        title: 'Update data object',
        description: 'Updates field values of a data object. Only the given fields change. Can also rename, move or (un)publish.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function updateObject(
        #[Schema(description: 'Object id or full path.')]
        string $object,
        #[Schema(definition: ['type' => 'object', 'description' => 'Field values keyed by field name.', 'additionalProperties' => true])]
        array $values = [],
        #[Schema(description: 'New key (rename).')]
        ?string $key = null,
        #[Schema(description: 'Move to this folder path.')]
        ?string $parentPath = null,
        ?bool $published = null,
    ): CallToolResult {
        $element = $this->ctx->object($object);
        $this->ctx->assertAllowed($element, 'save');
        $userId = $this->ctx->user()->getId();

        if ($values !== []) {
            if (!$element instanceof DataObject\Concrete) {
                throw new ToolCallException('Folders have no field values.');
            }
            $this->applyValues($element, $values);
        }
        if ($key !== null && $key !== $element->getKey()) {
            $this->ctx->assertAllowed($element, 'rename');
            $element->setKey(ElementService::getValidKey($key, 'object'));
        }
        if ($parentPath !== null) {
            $parent = $this->ctx->folder($parentPath, true);
            $this->ctx->assertAllowed($parent, 'create');
            $element->setParent($parent);
        }
        if ($published !== null && $element instanceof DataObject\Concrete) {
            $this->ctx->assertAllowed($element, $published ? 'publish' : 'unpublish');
            $element->setPublished($published);
        }

        $element->setUserModification($userId);
        $element->save(['versionNote' => 'Updated via MCP']);

        return McpContext::text($this->describe($element));
    }

    #[McpTool(
        name: 'pimcore_create_folder',
        title: 'Create folder',
        description: 'Creates a data object folder (and any missing parent folders).',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function createFolder(
        #[Schema(description: 'Full folder path, e.g. "/Products/Thermostats".')]
        string $path,
    ): CallToolResult {
        return McpContext::text($this->ctx->summary($this->ctx->folder($path, true)));
    }

    #[McpTool(
        name: 'pimcore_delete_objects',
        title: 'Delete data objects',
        description: 'Deletes data objects or folders (including everything below them) by id or path. Deleted elements go to the Pimcore recycle bin and can be restored there.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
    )]
    public function deleteObjects(
        #[Schema(definition: ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 200, 'description' => 'Object ids or full paths.'])]
        array $objects,
    ): CallToolResult {
        $user = $this->ctx->user();
        $deleted = [];
        $errors = [];
        foreach ($objects as $ref) {
            try {
                $element = $this->ctx->object((string) $ref);
                if ($element->getId() === 1) {
                    throw new ToolCallException('The root folder cannot be deleted.');
                }
                $this->ctx->assertAllowed($element, 'delete');
                $summary = $this->ctx->summary($element);
                Recyclebin\Item::create($element, $user);
                $element->delete();
                $deleted[] = $summary;
            } catch (\Throwable $e) {
                $errors[] = ['object' => $ref, 'error' => $e instanceof ToolCallException ? $e->getMessage() : 'Could not delete.'];
            }
        }

        return new CallToolResult(
            McpContext::text(['deleted' => $deleted, 'errors' => $errors])->content,
            isError: $deleted === [] && $errors !== [],
        );
    }

    private function describe(DataObject\AbstractObject $object): array
    {
        $out = $this->ctx->summary($object);
        if ($object instanceof DataObject\Concrete) {
            $values = [];
            foreach ($object->getClass()->getFieldDefinitions() as $name => $fd) {
                try {
                    $values[$name] = $this->ctx->normalize($object->get($name));
                } catch (\Throwable) {
                    $values[$name] = null;
                }
            }
            $out['values'] = $values;
        }

        return $out;
    }

    private function applyValues(DataObject\Concrete $object, array $values): void
    {
        $definitions = $object->getClass()->getFieldDefinitions();
        foreach ($values as $name => $value) {
            $fd = $definitions[$name] ?? null;
            if (!$fd instanceof Data) {
                throw new ToolCallException(sprintf(
                    'Unknown field "%s". Fields of %s: %s',
                    $name,
                    $object->getClassName(),
                    implode(', ', array_keys($definitions))
                ));
            }
            try {
                $object->set($name, $this->convert($fd, $value, $object));
            } catch (ToolCallException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ToolCallException(sprintf('Invalid value for "%s" (%s): %s', $name, $fd->getFieldtype(), $e->getMessage()));
            }
        }
    }

    private function convert(Data $fd, mixed $value, DataObject\Concrete $object): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($fd->getFieldtype()) {
            'input', 'textarea', 'wysiwyg', 'email', 'select', 'gender', 'country', 'language' => is_scalar($value) ? (string) $value : throw new ToolCallException('Expected a string.'),
            'numeric', 'slider' => is_numeric($value) ? $value + 0 : throw new ToolCallException('Expected a number.'),
            'checkbox', 'booleanSelect' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new ToolCallException('Expected true/false.'),
            'multiselect', 'countrymultiselect', 'languagemultiselect' => array_values(array_map('strval', (array) $value)),
            'date', 'datetime' => Carbon::parse((string) $value),
            default => $fd->getDataFromEditmode($value, $object),
        };
    }
}
