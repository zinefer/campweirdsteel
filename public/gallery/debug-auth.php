<?php
/**
 * Debug Authentication Script
 * Debug the simplified Laravel-style authentication
 */

require_once '../../includes/gallery/auth.php';

header('Content-Type: application/json');

$debug = [];

try {
    $auth = new GalleryAuth();
    
    // Get cookie factory for proper debugging
    $cookieFactory = $auth->getCookieFactory();
    
    // Check session cookie name and value
    $sessionCookieName = $cookieFactory->getName('session');
    
    $debug['cookie_info'] = [
        'session_cookie_name' => $sessionCookieName,
        'session_cookie_exists' => isset($_COOKIE[$sessionCookieName]),
        'session_cookie_value' => isset($_COOKIE[$sessionCookieName]) ? substr($_COOKIE[$sessionCookieName], 0, 20) . '...' : null,
        'session_cookie_length' => isset($_COOKIE[$sessionCookieName]) ? strlen($_COOKIE[$sessionCookieName]) : 0
    ];
    
    // Show all cookies for reference
    $debug['all_cookies'] = [];
    foreach ($_COOKIE as $name => $value) {
        $debug['all_cookies'][$name] = strlen($value) . ' chars';
    }
    
    // Test the session file reading directly
    if (isset($_COOKIE[$sessionCookieName])) {
        $sessionId = $_COOKIE[$sessionCookieName];
        
        try {
            // Test session file existence
            $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
            $sessionFile = $sessionPath . '/sess_' . $sessionId;
            
            $debug['session_file'] = [
                'path' => $sessionFile,
                'exists' => file_exists($sessionFile),
                'readable' => file_exists($sessionFile) && is_readable($sessionFile),
                'size' => file_exists($sessionFile) ? filesize($sessionFile) : 0,
                'modified' => file_exists($sessionFile) ? date('Y-m-d H:i:s', filemtime($sessionFile)) : null
            ];
            
            // Try to read session data using Laravel's approach
            if (file_exists($sessionFile)) {
                $sessionContent = file_get_contents($sessionFile);
                if ($sessionContent !== false) {
                    try {
                        $sessionData = unserialize($sessionContent);
                        $debug['session_data'] = [
                            'has_access_token' => isset($sessionData['access_token']),
                            'access_token_preview' => isset($sessionData['access_token']) ? substr($sessionData['access_token'], 0, 20) . '...' : null,
                            'keys' => array_keys($sessionData),
                            'csrf_token' => isset($sessionData['_token']) ? substr($sessionData['_token'], 0, 10) . '...' : null
                        ];
                        
                        // If we have an access token, test database lookup
                        if (isset($sessionData['access_token'])) {
                            $accessToken = $sessionData['access_token'];
                            
                            // Test database connection
                            $container = $auth->getApp()->getContainer();
                            $db = $container->make('flarum.db');
                            
                            $tokenData = $db->table('access_tokens')
                                ->where('token', $accessToken)
                                ->first();
                            
                            $debug['token_lookup'] = [
                                'token_found_in_db' => $tokenData !== null,
                                'token_user_id' => $tokenData ? $tokenData->user_id : null,
                                'token_type' => $tokenData ? $tokenData->type : null,
                                'token_created' => $tokenData ? $tokenData->created_at : null,
                                'token_last_activity' => $tokenData ? $tokenData->last_activity_at : null
                            ];
                            
                            // If token found, test user lookup
                            if ($tokenData && $tokenData->user_id) {
                                $userData = $db->table('users')
                                    ->where('id', $tokenData->user_id)
                                    ->first();
                                
                                $debug['user_lookup'] = [
                                    'user_found_in_db' => $userData !== null,
                                    'user_id' => $userData ? $userData->id : null,
                                    'username' => $userData ? $userData->username : null,
                                    'email' => $userData ? $userData->email : null,
                                    'joined_at' => $userData ? $userData->joined_at : null,
                                    'available_fields' => $userData ? array_keys((array)$userData) : []
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        $debug['session_parse_error'] = $e->getMessage();
                    }
                } else {
                    $debug['session_read_error'] = 'Could not read session file';
                }
            }
        } catch (Exception $e) {
            $debug['session_file_error'] = $e->getMessage();
        }
    }
    
    // Test authentication
    $debug['authentication'] = [
        'is_authenticated' => $auth->isAuthenticated(),
        'user_type' => get_class($auth->getUser())
    ];
    
    $user = $auth->getUser();
    if ($user && is_callable([$user, 'isGuest']) && !$user->isGuest()) {
        $debug['authenticated_user'] = [
            'id' => $user->id ?? null,
            'username' => $user->username ?? null,
            'display_name' => $user->display_name ?? null,
            'email' => $user->email ?? null
        ];
    } elseif ($user && !is_callable([$user, 'isGuest'])) {
        // Handle our custom user object
        $debug['authenticated_user'] = [
            'id' => $user->id ?? null,
            'username' => $user->username ?? null,
            'display_name' => $user->display_name ?? null,
            'email' => $user->email ?? null
        ];
    }
    
    // Database connectivity check
    try {
        $container = $auth->getApp()->getContainer();
        $db = $container->make('flarum.db');
        
        $tokenCount = $db->table('access_tokens')->count();
        $userCount = $db->table('users')->count();
        
        $debug['database'] = [
            'connection' => 'OK',
            'total_tokens' => $tokenCount,
            'total_users' => $userCount
        ];
        
        // Sample recent tokens for debugging
        $recentTokens = $db->table('access_tokens')
            ->orderBy('last_activity_at', 'desc')
            ->take(3)
            ->get();
        
        $debug['recent_tokens'] = [];
        foreach ($recentTokens as $token) {
            $debug['recent_tokens'][] = [
                'id' => $token->id,
                'type' => $token->type,
                'user_id' => $token->user_id,
                'token_prefix' => substr($token->token, 0, 10) . '...',
                'last_activity' => $token->last_activity_at,
            ];
        }
    } catch (Exception $e) {
        $debug['database'] = [
            'connection' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    // Session storage check
    $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
    $debug['session_storage'] = [
        'path' => $sessionPath,
        'exists' => is_dir($sessionPath),
        'writable' => is_writable($sessionPath),
        'files_count' => is_dir($sessionPath) ? count(glob($sessionPath . '/sess_*')) : 0
    ];
    
} catch (Exception $e) {
    $debug['fatal_error'] = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
}

echo json_encode($debug, JSON_PRETTY_PRINT);
?>
