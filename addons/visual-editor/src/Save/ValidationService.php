<?php

namespace Dbvc\VisualEditor\Save;

use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\ResolverInterface;

final class ValidationService
{
    /**
     * @param ResolverInterface  $resolver
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function validate(ResolverInterface $resolver, EditableDescriptor $descriptor, $value)
    {
        return $resolver->validate($descriptor, $value);
    }
}
