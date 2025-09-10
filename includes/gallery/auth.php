<?php
/**
 * Gallery Authentication Helper
 * Simple implementation following Laravel integration pattern
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Flarum\Http\CookieFactory;
use Flarum\User\Guest;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Filesystem\Filesystem;

class GalleryAuth {
    private $site;
    private $app;
    private $user = null;
    private $cookieFactory = null;
    
    public function __construct() {
        // Load Flarum site configuration and boot app
        $this->site = require dirname(__DIR__, 2) . '/site.php';
        $this->app = $this->site->bootApp();
        
        // Get cookie factory for proper cookie name resolution
        $container = $this->app->getContainer();
        $this->cookieFactory = $container->make(CookieFactory::class);
    }
    
    /**
     * Check authentication using simple session file reading approach
     */
    public function isAuthenticated() {
        try {
            // Get Flarum session cookie name and value
            $sessionCookieName = $this->cookieFactory->getName('session');
            $sessionId = $_COOKIE[$sessionCookieName] ?? null;
            
            if (!$sessionId) {
                $this->user = new Guest();
                return false;
            }
            
            // Create session store using Flarum's file session handler
            $sessionStore = new Store(
                'gallery-auth', 
                $this->getFileSessionHandler(), 
                $sessionId
            );
            
            // Start session to read data
            $sessionStore->start();
            
            // Get access token from session
            $accessToken = $sessionStore->get('access_token');
            
            if (!$accessToken) {
                $this->user = new Guest();
                return false;
            }
            
            // Look up the token in Flarum's database
            $container = $this->app->getContainer();
            $db = $container->make('flarum.db');
            
            $tokenData = $db->table('access_tokens')
                ->where('token', $accessToken)
                ->first();
            
            if (!$tokenData || !$tokenData->user_id) {
                $this->user = new Guest();
                return false;
            }
            
            // Get the user from Flarum's database
            $userData = $db->table('users')
                ->where('id', $tokenData->user_id)
                ->first();
            
            if (!$userData) {
                $this->user = new Guest();
                return false;
            }
            
            // Create a user object (we'll use a simple stdClass for now)
            $this->user = (object) [
                'id' => $userData->id,
                'username' => $userData->username,
                'display_name' => $userData->display_name ?? $userData->username, // fallback to username if display_name doesn't exist
                'email' => $userData->email,
                'is_email_confirmed' => $userData->is_email_confirmed ?? false,
                'joined_at' => $userData->joined_at ?? null,
                'last_seen_at' => $userData->last_seen_at ?? null,
                'isGuest' => function() { return false; }
            ];
            
            return true;
            
        } catch (Exception $e) {
            error_log("Gallery Auth: Authentication error - " . $e->getMessage());
            $this->user = new Guest();
            return false;
        }
    }
    
    /**
     * Create file session handler like Laravel integration does
     */
    private function getFileSessionHandler() {
        $filesystem = new Filesystem();
        $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
        $lifetimeMinutes = 120; // 2 hours default
        
        return new FileSessionHandler($filesystem, $sessionPath, $lifetimeMinutes);
    }
    
    /**
     * Get authenticated user
     */
    public function getUser() {
        return $this->user;
    }
    
    /**
     * Require authentication or show unauthorized page
     */
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            $this->showUnauthorizedPage();
            exit;
        }
    }
    
    /**
     * Show a nice unauthorized page instead of raw JSON
     */
    private function showUnauthorizedPage() {
        // Set 403 status but serve HTML
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        
        // Include the site's header and navigation
        require_once dirname(__DIR__) . '/header.php';
        require_once dirname(__DIR__) . '/navigation.php';
        
        renderHeader("Access Denied - Weird Steel Gallery");
        renderNavigation('gallery');
        ?>
        
        <style>
            .unauthorized-container {
                min-height: 70vh;
                display: flex;
                align-items: center;
                justify-content: center;
                text-align: center;
                padding: 2rem;
            }
            
            .unauthorized-content {
                max-width: 600px;
                background: rgba(0, 0, 0, 0.8);
                padding: 3rem 2rem;
                border-radius: 12px;
                border: 2px solid #ff6b35;
                backdrop-filter: blur(10px);
                box-shadow: 0 20px 40px rgba(255, 107, 53, 0.3);
            }
            
            .unauthorized-title {
                font-size: 2.5rem;
                color: #ff6b35;
                margin-bottom: 1rem;
                font-weight: bold;
            }
            
            .unauthorized-subtitle {
                font-size: 1.2rem;
                color: #ffd700;
                margin-bottom: 2rem;
            }
            
            .unauthorized-message {
                font-size: 1.1rem;
                color: #ffffff;
                line-height: 1.6;
                margin-bottom: 2rem;
            }
            
            .unauthorized-actions {
                display: flex;
                gap: 1rem;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .unauthorized-btn {
                padding: 12px 24px;
                border: 2px solid #ff6b35;
                background: transparent;
                color: #ff6b35;
                text-decoration: none;
                border-radius: 6px;
                font-size: 1rem;
                font-weight: bold;
                transition: all 0.3s ease;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .unauthorized-btn:hover {
                background: #ff6b35;
                color: #000;
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(255, 107, 53, 0.4);
            }
            
            .unauthorized-btn.primary {
                background: #ff6b35;
                color: #000;
            }
            
            .unauthorized-btn.primary:hover {
                background: #ffd700;
                border-color: #ffd700;
            }
            
            .fire-icon {
                font-size: 1.2em;
            }
            
            @media (max-width: 768px) {
                .unauthorized-content {
                    padding: 2rem 1.5rem;
                    margin: 1rem;
                }
                
                .unauthorized-title {
                    font-size: 2rem;
                }
                
                .unauthorized-actions {
                    flex-direction: column;
                    align-items: center;
                }
                
                .unauthorized-btn {
                    width: 100%;
                    max-width: 250px;
                    justify-content: center;
                }
            }
        </style>
        
        <div class="unauthorized-container">
            <div class="unauthorized-content">
                <h1 class="unauthorized-title">🔥 Access Denied</h1>
                <h2 class="unauthorized-subtitle">Gallery Members Only</h2>
                
                <div class="unauthorized-message">
                    <p>The Weird Steel Gallery is a private space for our camp family to share their Burning Man memories and experiences.</p>
                    <p>You need to be logged in as a registered camp member to access the gallery.</p>
                </div>
                
                <div class="unauthorized-actions">
                    <a href="../forum/" class="unauthorized-btn primary">
                        <span class="fire-icon">🔥</span>
                        Join the Community
                    </a>
                    <a href="../" class="unauthorized-btn">
                        <span class="fire-icon">🏠</span>
                        Back to Home
                    </a>
                </div>
            </div>
        </div>
        
        <?php
        require_once dirname(__DIR__) . '/fire-footer.php';
        ?>
        
        <script src="../assets/js/main.js"></script>
        </body>
        </html>
        <?php
    }
    
    /**
     * Check if user can upload for the current year
     */
    public function canUploadForYear($year) {
        $currentYear = (int)date('Y');
        return $year == $currentYear;
    }
    
    /**
     * Get the Flarum app instance (for debugging)
     */
    public function getApp() {
        return $this->app;
    }
    
    /**
     * Get cookie factory (for debugging)
     */
    public function getCookieFactory() {
        return $this->cookieFactory;
    }
}
?>
