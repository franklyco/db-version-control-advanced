<?php

namespace Dbvc\VisualEditor\Registry;

use Dbvc\VisualEditor\Context\PageContextResolver;

final class EditableRegistry
{
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_session_';
    private const SESSION_TTL = 900;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var array<string, EditableDescriptor>
     */
    private $descriptors = [];

    /**
     * @var string
     */
    private $session_id = '';

    public function __construct(PageContextResolver $page_context)
    {
        $this->page_context = $page_context;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return void
     */
    public function add(EditableDescriptor $descriptor)
    {
        $this->descriptors[$descriptor->token] = $descriptor;
        $this->getSessionId();
    }

    /**
     * @param string $token
     * @return void
     */
    public function remove($token)
    {
        $token = sanitize_key((string) $token);
        if ($token === '') {
            return;
        }

        unset($this->descriptors[$token]);
    }

    /**
     * @return string
     */
    public function getSessionId()
    {
        if ($this->session_id === '') {
            $this->session_id = 'ves_' . strtolower(wp_generate_password(12, false, false));
        }

        return $this->session_id;
    }

    /**
     * @param string $seed
     * @return string
     */
    public function createToken($seed)
    {
        return 've_' . substr(hash('sha256', $this->getSessionId() . '|' . (string) $seed), 0, 12);
    }

    /**
     * @return void
     */
    public function persistRequestSession()
    {
        if (empty($this->descriptors)) {
            return;
        }

        $page_context = $this->page_context->resolve();
        if (empty($page_context['isSupported'])) {
            return;
        }

        $payload = [
            'session_id' => $this->getSessionId(),
            'user_id' => get_current_user_id(),
            'page_context' => $page_context,
            'descriptors' => array_map(
                static function (EditableDescriptor $descriptor) {
                    return $descriptor->toArray();
                },
                $this->descriptors
            ),
            'public_map' => $this->exportPublicMap($this->descriptors),
            'created_at' => time(),
        ];

        set_transient($this->getTransientKey($this->session_id), $payload, self::SESSION_TTL);
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>
     */
    public function loadSession($session_id)
    {
        $session_id = $this->normalizeSessionId($session_id);
        if ($session_id === '') {
            return [];
        }

        $payload = get_transient($this->getTransientKey($session_id));
        if (! is_array($payload)) {
            return [];
        }

        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return [];
        }

        set_transient($this->getTransientKey($session_id), $payload, self::SESSION_TTL);

        return $payload;
    }

    /**
     * @param string $session_id
     * @param string $token
     * @return EditableDescriptor|null
     */
    public function getDescriptorFromSession($session_id, $token)
    {
        $session = $this->loadSession($session_id);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $token = sanitize_key((string) $token);

        if ($token === '' || ! isset($descriptors[$token]) || ! is_array($descriptors[$token])) {
            return null;
        }

        return EditableDescriptor::fromArray($descriptors[$token]);
    }

    /**
     * @param string $session_id
     * @return array<string, EditableDescriptor>
     */
    public function getDescriptorsFromSession($session_id)
    {
        $session = $this->loadSession($session_id);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $resolved = [];

        foreach ($descriptors as $token => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = EditableDescriptor::fromArray($payload);
        }

        return $resolved;
    }

    /**
     * @param array<string, EditableDescriptor>|null $descriptors
     * @return array<string, array<string, string>>
     */
    public function exportPublicMap($descriptors = null)
    {
        $descriptors = is_array($descriptors) ? $descriptors : $this->descriptors;
        $map = [];

        foreach ($descriptors as $token => $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $map[$token] = [
                'token' => (string) $token,
                'status' => (string) $descriptor->status,
                'scope' => (string) $descriptor->scope,
                'label' => isset($descriptor->ui['label']) ? (string) $descriptor->ui['label'] : __('Field', 'dbvc'),
                'input' => isset($descriptor->ui['input']) ? (string) $descriptor->ui['input'] : 'text',
                'entity' => $this->exportPublicEntitySummary($descriptor),
            ];
        }

        return $map;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function exportPublicEntitySummary(EditableDescriptor $descriptor)
    {
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];

        return [
            'type' => isset($entity['type']) ? sanitize_key((string) $entity['type']) : '',
            'id' => isset($entity['id']) ? absint($entity['id']) : 0,
            'subtype' => isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '',
        ];
    }

    /**
     * @param string $session_id
     * @return string
     */
    private function getTransientKey($session_id)
    {
        return self::TRANSIENT_PREFIX . $this->normalizeSessionId($session_id);
    }

    /**
     * @param string $session_id
     * @return string
     */
    private function normalizeSessionId($session_id)
    {
        return sanitize_key((string) $session_id);
    }
}
