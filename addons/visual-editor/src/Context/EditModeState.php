<?php

namespace Dbvc\VisualEditor\Context;

use Dbvc\VisualEditor\Permissions\CapabilityManager;

final class EditModeState
{
    private const COOKIE_NAME = 'dbvc_visual_editor_mode';
    private const TOGGLE_QUERY_ARG = 'dbvc_visual_editor';
    private const NONCE_QUERY_ARG = '_dbvcve_nonce';
    private const NONCE_ACTION = 'dbvc_visual_editor_toggle';

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    public function __construct(CapabilityManager $capabilities, PageContextResolver $page_context)
    {
        $this->capabilities = $capabilities;
        $this->page_context = $page_context;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('template_redirect', [$this, 'handleToggleRequest'], 1);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('template_redirect', [$this, 'handleToggleRequest'], 1);
    }

    /**
     * @return void
     */
    public function handleToggleRequest()
    {
        if (! isset($_GET[self::TOGGLE_QUERY_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if (! $this->capabilities->canUseVisualEditor()) {
            return;
        }

        $toggle = sanitize_text_field(wp_unslash((string) $_GET[self::TOGGLE_QUERY_ARG])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($toggle, ['0', '1'], true)) {
            return;
        }

        $nonce = isset($_GET[self::NONCE_QUERY_ARG]) ? wp_unslash((string) $_GET[self::NONCE_QUERY_ARG]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if ($toggle === '1' && ! $this->page_context->isSupported()) {
            $redirect = remove_query_arg([self::TOGGLE_QUERY_ARG, self::NONCE_QUERY_ARG]);
            wp_safe_redirect($redirect);
            exit;
        }

        if (headers_sent()) {
            return;
        }

        $this->setCookie($toggle === '1');

        $redirect = remove_query_arg([self::TOGGLE_QUERY_ARG, self::NONCE_QUERY_ARG]);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        if (! $this->capabilities->canUseVisualEditor()) {
            return false;
        }

        if (is_admin() || ! $this->page_context->isSupported()) {
            return false;
        }

        return $this->getCookieValue() === '1';
    }

    /**
     * @return bool
     */
    public function shouldLoadFrontendAssets()
    {
        return $this->isActive();
    }

    /**
     * REST requests do not have the original singular frontend query context.
     *
     * @return bool
     */
    public function isRestRequestAuthorized()
    {
        if (! $this->capabilities->canUseVisualEditor()) {
            return false;
        }

        return $this->getCookieValue() === '1';
    }

    /**
     * @return bool
     */
    public function canRenderToggle()
    {
        if (is_admin() || ! $this->capabilities->canUseVisualEditor()) {
            return false;
        }

        return $this->page_context->isSupported() || $this->getCookieValue() === '1';
    }

    /**
     * @return string
     */
    public function buildToggleUrl()
    {
        $toggle = $this->isActive() ? '0' : '1';

        return esc_url(
            add_query_arg(
                [
                    self::TOGGLE_QUERY_ARG => $toggle,
                    self::NONCE_QUERY_ARG => wp_create_nonce(self::NONCE_ACTION),
                ]
            )
        );
    }

    /**
     * @param bool $active
     * @return void
     */
    private function setCookie($active)
    {
        $value = $active ? '1' : '0';
        $secure = is_ssl();
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie(self::COOKIE_NAME, $value, 0, $path, $domain, $secure, true);
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    /**
     * @return string
     */
    private function getCookieValue()
    {
        return isset($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE_NAME])) : '0';
    }
}
