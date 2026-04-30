<?php

namespace Dbvc\VisualEditor\Save;

use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\ResolverInterface;

final class SanitizationService
{
    /**
     * @param ResolverInterface  $resolver
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(ResolverInterface $resolver, EditableDescriptor $descriptor, $value)
    {
        return $resolver->sanitize($descriptor, $value);
    }
}
