<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Exception\ToolCallException;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\User;
use Pimcore\Security\User\TokenStorageUserResolver;

/**
 * Shared helpers for the MCP tools: the Pimcore user behind the token, permission
 * checks, element lookup and JSON-safe value conversion.
 */
final class McpContext
{
    public function __construct(
        private readonly TokenStorageUserResolver $userResolver,
    ) {
    }

    public function user(): User
    {
        $user = $this->userResolver->getUser();
        if (!$user instanceof User) {
            throw new ToolCallException('No authenticated Pimcore user.');
        }

        return $user;
    }

    public function assertAllowed(ElementInterface $element, string $permission): void
    {
        if (!$element->isAllowed($permission, $this->user())) {
            throw new ToolCallException(sprintf(
                'Permission "%s" denied on %s.',
                $permission,
                $element->getRealFullPath()
            ));
        }
    }

    public function assertUserPermission(string $permission): void
    {
        if (!$this->user()->isAllowed($permission)) {
            throw new ToolCallException(sprintf('User permission "%s" is required.', $permission));
        }
    }

    public function object(int|string $idOrPath): DataObject\AbstractObject
    {
        $object = is_int($idOrPath) || ctype_digit((string) $idOrPath)
            ? DataObject::getById((int) $idOrPath, ['force' => true])
            : DataObject::getByPath($this->normalizePath((string) $idOrPath), ['force' => true]);

        if (!$object instanceof DataObject\AbstractObject) {
            throw new ToolCallException(sprintf('Data object "%s" not found.', $idOrPath));
        }
        $this->assertAllowed($object, 'view');

        return $object;
    }

    /**
     * Returns the folder at $path, creating missing folders on the way when $create is true.
     */
    public function folder(string $path, bool $create = false): DataObject\AbstractObject
    {
        $path = $this->normalizePath($path);
        $existing = DataObject::getByPath($path, ['force' => true]);
        if ($existing instanceof DataObject\AbstractObject) {
            return $existing;
        }
        if (!$create) {
            throw new ToolCallException(sprintf('Parent "%s" not found.', $path));
        }

        $parent = $this->folder(dirname($path) ?: '/', true);
        $this->assertAllowed($parent, 'create');

        $folder = new DataObject\Folder();
        $folder->setKey(\Pimcore\Model\Element\Service::getValidKey(basename($path), 'object'));
        $folder->setParent($parent);
        $folder->setUserOwner($this->user()->getId());
        $folder->setUserModification($this->user()->getId());
        $folder->save();

        return $folder;
    }

    public function normalizePath(string $path): string
    {
        $path = '/' . trim(trim($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function summary(DataObject\AbstractObject $object): array
    {
        return [
            'id' => $object->getId(),
            'key' => $object->getKey(),
            'path' => $object->getRealFullPath(),
            'type' => $object->getType(),
            'class' => $object instanceof DataObject\Concrete ? $object->getClassName() : null,
            'published' => $object instanceof DataObject\Concrete ? $object->getPublished() : null,
            'modified' => $object->getModificationDate() ? date(DATE_ATOM, $object->getModificationDate()) : null,
        ];
    }

    public function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($depth > 4) {
            return '…';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof ElementInterface) {
            return ['elementType' => \Pimcore\Model\Element\Service::getElementType($value), 'id' => $value->getId(), 'path' => $value->getRealFullPath()];
        }
        if ($value instanceof DataObject\Data\QuantityValue) {
            return ['value' => $value->getValue(), 'unit' => $value->getUnit()?->getAbbreviation()];
        }
        if (is_iterable($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalize($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (method_exists($value, 'getObjectVars')) {
            return $this->normalize($value->getObjectVars(), $depth + 1);
        }

        return get_class($value);
    }

    public static function text(mixed $data): \Mcp\Schema\Result\CallToolResult
    {
        return new \Mcp\Schema\Result\CallToolResult([
            new \Mcp\Schema\Content\TextContent(
                is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
        ]);
    }
}
