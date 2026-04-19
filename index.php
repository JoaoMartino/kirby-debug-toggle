<?php

/**
 * Kirby 5 Debug Toggle Plugin
 * 
 * REQUIRED: Add this to site/config/config.php:
 * 
 * 'debug' => (function() {
 *     $flag = __DIR__ . '/.debug_enabled';
 *     if (!file_exists($flag)) return false;
 *     $data = @json_decode(file_get_contents($flag), true);
 *     if (!$data || empty($data['expires_at'])) return false;
 *     return time() < $data['expires_at'];
 * })(),
 * 
 * OPTIONAL: Plugin configuration (uses defaults if omitted):
 * 
 * 'Martino.debug-toggle.expiry-hours' => 4,  // Hours until auto-disable (default: 4)
 * 'Martino.debug-toggle.permission' => 'admin',  // Who can toggle (default: 'admin')
 * 'Martino.debug-toggle.panel-auto-debug' => true,  // Auto-enable debug for panel (default: true)
 * 
 * Permission examples:
 * 'Martino.debug-toggle.permission' => 'admin',  // Only admins (uses isAdmin())
 * 'Martino.debug-toggle.permission' => 'editor',  // Only users with 'editor' role
 * 'Martino.debug-toggle.permission' => function($user) { return $user->email() === 'dev@example.com'; }
 */

use Kirby\Cms\App as Kirby;
use Kirby\Exception\PermissionException;

function debugFlagPath(): string
{
    return kirby()->root('config') . '/.debug_enabled';
}

function debugFlagData(): array
{
    $path = debugFlagPath();
    if (!file_exists($path)) {
        return [];
    }
    
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function debugFlagExpired(): bool
{
    $data = debugFlagData();
    if (empty($data) || empty($data['expires_at'])) {
        return false;
    }
    
    return time() >= $data['expires_at'];
}

function debugFlagActive(): bool
{
    return file_exists(debugFlagPath()) && !debugFlagExpired();
}

function debugShouldEnable(): bool
{
    try {
        $kirby = kirby();
        
        // Auto-enable debug for panel when authorized users are logged in
        if ($kirby->user()) {
            $panelAutoDebug = $kirby->option('Martino.debug-toggle.panel-auto-debug', true);
            if ($panelAutoDebug && $kirby->path() && str_starts_with($kirby->path(), 'panel')) {
                $permission = $kirby->option('Martino.debug-toggle.permission', 'admin');
                $user = $kirby->user();
                
                // Check permission
                if (is_callable($permission)) {
                    if ($permission($user)) return true;
                } elseif ($permission === 'admin' && $user->isAdmin()) {
                    return true;
                } elseif (is_string($permission) && $user->role()->name() === $permission) {
                    return true;
                }
            }
        }
        
        // Check flag file for manual toggle
        return debugFlagActive();
    } catch (\Throwable $e) {
        // If called too early (before Kirby is initialized), just check the flag file
        return debugFlagActive();
    }
}

function debugHasPermission(): bool
{
    $kirby = kirby();
    $user = $kirby->user();
    
    if (!$user) {
        return false;
    }
    
    $permission = $kirby->option('Martino.debug-toggle.permission', 'admin');
    
    // Check if it's a callback function
    if (is_callable($permission)) {
        return $permission($user);
    }
    
    // Check for special 'admin' keyword
    if ($permission === 'admin') {
        return $user->isAdmin();
    }
    
    // Check by role name (editor, developer, etc.)
    if (is_string($permission)) {
        return $user->role()->name() === $permission;
    }
    
    return false;
}

function ensureGitIgnore(): void
{
    $gitignorePath = kirby()->root('index') . '/.gitignore';
    $flagEntry = 'site/config/.debug_enabled';
    
    if (file_exists($gitignorePath)) {
        $content = file_get_contents($gitignorePath);
        if ($content !== false && strpos($content, $flagEntry) !== false) {
            return;
        }
        
        $append = (substr($content, -1) === "\n") ? $flagEntry . "\n" : "\n" . $flagEntry . "\n";
        file_put_contents($gitignorePath, $content . $append);
    } else {
        file_put_contents($gitignorePath, $flagEntry . "\n");
    }
}

Kirby::plugin('Martino/debug-toggle', [
    'options' => [
        'expiry-hours' => 4,
        'permission' => 'admin',
        'panel-auto-debug' => true
    ],
    
    'hooks' => [
        'system.loadPlugins:after' => function() {
            $kirby = kirby();
            
            // Enable debug for panel when authorized users are logged in
            if ($kirby->user() && $kirby->option('Martino.debug-toggle.panel-auto-debug', true)) {
                if ($kirby->path() && str_starts_with($kirby->path(), 'panel')) {
                    if (debugHasPermission()) {
                        $kirby->extend([
                            'options' => [
                                'debug' => true
                            ]
                        ]);
                    }
                }
            }
        }
    ],
    
    'assets' => [
        'css/debug-toggle.css' => __DIR__ . '/index.css'
    ],
    
    'areas' => [
        'debug-toggle' => function ($kirby) {
            if (!debugHasPermission()) {
                return [];
            }
            
            ensureGitIgnore();
            
            return [
                'label' => 'Debug',
                'icon' => 'bug',
                'menu' => true,
                'link' => 'debug-toggle',
                'views' => [
                    [
                        'pattern' => 'debug-toggle',
                        'action' => function () {
                            return [
                                'component' => 'k-debug-toggle-view',
                                'title' => 'Debug Mode'
                            ];
                        }
                    ]
                ]
            ];
        }
    ],
    
    'api' => [
        'routes' => [
            [
                'pattern' => 'debug-toggle/state',
                'method' => 'GET',
                'action' => function () {
                    if (!debugHasPermission()) {
                        throw new PermissionException('You do not have permission to access debug toggle');
                    }
                    
                    if (file_exists(debugFlagPath()) && debugFlagExpired()) {
                        unlink(debugFlagPath());
                    }
                    
                    $data = debugFlagData();
                    $active = debugFlagActive();
                    
                    return [
                        'debug' => $active,
                        'enabled_by' => $data['enabled_by'] ?? null,
                        'enabled_at' => !empty($data['enabled_at']) ? date('Y-m-d H:i', $data['enabled_at']) : null,
                        'expires_at' => !empty($data['expires_at']) ? date('Y-m-d H:i', $data['expires_at']) : null,
                        'expired' => !empty($data) && debugFlagExpired()
                    ];
                }
            ],
            [
                'pattern' => 'debug-toggle/state',
                'method' => 'POST',
                'action' => function () {
                    if (!debugHasPermission()) {
                        throw new PermissionException('You do not have permission to modify debug toggle');
                    }
                    
                    $kirby = kirby();
                    
                    $enabled = $kirby->request()->get('enabled', false);
                    $flagPath = debugFlagPath();
                    
                    if ($enabled) {
                        $expiryHours = $kirby->option('Martino.debug-toggle.expiry-hours', 4);
                        $now = time();
                        
                        $payload = [
                            'enabled_by' => $kirby->user()->email(),
                            'enabled_at' => $now,
                            'expires_at' => $now + ($expiryHours * 3600)
                        ];
                        
                        file_put_contents($flagPath, json_encode($payload, JSON_PRETTY_PRINT));
                        chmod($flagPath, 0600);
                        
                        return [
                            'debug' => true,
                            'enabled_by' => $payload['enabled_by'],
                            'enabled_at' => date('Y-m-d H:i', $payload['enabled_at']),
                            'expires_at' => date('Y-m-d H:i', $payload['expires_at']),
                            'expired' => false
                        ];
                    } else {
                        if (file_exists($flagPath)) {
                            unlink($flagPath);
                        }
                        
                        return [
                            'debug' => false,
                            'enabled_by' => null,
                            'enabled_at' => null,
                            'expires_at' => null,
                            'expired' => false
                        ];
                    }
                }
            ]
        ]
    ]
]);
