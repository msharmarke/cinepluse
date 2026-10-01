<?php
/**
 * Cinepulse — Admin API Proxy / Alias Fallback
 * Redirects legacy or relative /admin/api requests directly to public/api.php
 */
require_once dirname(__DIR__) . '/api.php';
