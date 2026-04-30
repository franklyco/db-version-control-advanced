<?php

namespace Dbvc\VisualEditor\Registry;

final class EditableDescriptor
{
    /**
     * @var string
     */
    public $token;

    /**
     * @var string
     */
    public $status;

    /**
     * @var string
     */
    public $scope;

    /**
     * @var array<string, mixed>
     */
    public $entity;

    /**
     * @var array<string, mixed>
     */
    public $render;

    /**
     * @var array<string, mixed>
     */
    public $source;

    /**
     * @var array<string, mixed>
     */
    public $ui;

    /**
     * @var array<string, mixed>
     */
    public $resolver;

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $render
     * @param array<string, mixed> $source
     * @param array<string, mixed> $ui
     * @param array<string, mixed> $resolver
     */
    public function __construct($token, $status, $scope, array $entity, array $render, array $source, array $ui, array $resolver)
    {
        $this->token = (string) $token;
        $this->status = (string) $status;
        $this->scope = (string) $scope;
        $this->entity = $entity;
        $this->render = $render;
        $this->source = $source;
        $this->ui = $ui;
        $this->resolver = $resolver;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'token' => $this->token,
            'status' => $this->status,
            'scope' => $this->scope,
            'entity' => $this->entity,
            'render' => $this->render,
            'source' => $this->source,
            'ui' => $this->ui,
            'resolver' => $this->resolver,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return self
     */
    public static function fromArray(array $payload)
    {
        return new self(
            isset($payload['token']) ? (string) $payload['token'] : '',
            isset($payload['status']) ? (string) $payload['status'] : 'unsupported',
            isset($payload['scope']) ? (string) $payload['scope'] : 'current_entity',
            isset($payload['entity']) && is_array($payload['entity']) ? $payload['entity'] : [],
            isset($payload['render']) && is_array($payload['render']) ? $payload['render'] : [],
            isset($payload['source']) && is_array($payload['source']) ? $payload['source'] : [],
            isset($payload['ui']) && is_array($payload['ui']) ? $payload['ui'] : [],
            isset($payload['resolver']) && is_array($payload['resolver']) ? $payload['resolver'] : []
        );
    }
}
