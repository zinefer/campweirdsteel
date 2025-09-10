<?php
function renderNavigation($currentPage = 'home') {
    $navItems = [
        'home' => ['href' => '../', 'text' => 'Home'],
        //'about' => ['href' => '../#about', 'text' => 'About'],
        //'art' => ['href' => '../#art', 'text' => 'Art'],
        //'contact' => ['href' => '../#contact', 'text' => 'Contact'],
        'gallery' => ['href' => '../gallery/', 'text' => 'Gallery'],
        'members' => ['href' => '../forum/', 'text' => 'Members', 'highlight' => true]
    ];
    
    // Adjust paths for main site vs subpages
    if ($currentPage === 'home') {
        foreach ($navItems as $key => &$item) {
            // Remove the '../' prefix for home page
            $item['href'] = str_replace('../', '', $item['href']);
            
            // Handle empty href (which would be the home link)
            if ($item['href'] === '' || $item['href'] === '/') {
                $item['href'] = '#home';
            }
            
            // Handle paths that start with '#' after removal
            if (strpos($item['href'], '#') === 0) {
                // Keep as-is, it's already a fragment
                continue;
            }
            
            // For relative paths that aren't fragments, ensure they're proper
            if (!empty($item['href']) && $item['href'] !== '#home') {
                // Add './' prefix for relative paths on home page
                if (!str_starts_with($item['href'], './') && !str_starts_with($item['href'], '/')) {
                    $item['href'] = './' . $item['href'];
                }
            }
        }
        unset($item); // Break the reference to avoid issues
    }
?>
    <!-- Navigation -->
    <nav id="navbar">
        <div class="nav-content">
            <div class="nav-logo">WEIRD STEEL</div>
            <div class="nav-links">
                <?php foreach ($navItems as $key => $item): ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" 
                       <?php if ($key === $currentPage): ?>
                           style="color: #fff; font-weight: bold;"
                       <?php elseif (isset($item['highlight']) && $item['highlight']): ?>
                           style="color: #ff6b35;"
                       <?php endif; ?>>
                        <?php echo htmlspecialchars($item['text']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
<?php
}
?>