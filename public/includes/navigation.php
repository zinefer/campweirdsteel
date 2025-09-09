<?php
function renderNavigation($currentPage = 'home') {
    $navItems = [
        'home' => ['href' => '#home', 'text' => 'Home'],
        'about' => ['href' => '#about', 'text' => 'About'],
        'art' => ['href' => '#art', 'text' => 'Art'],
        'contact' => ['href' => '#contact', 'text' => 'Contact'],
        'members' => ['href' => 'forum/', 'text' => 'Members', 'highlight' => true]
    ];
?>
    <!-- Navigation -->
    <nav id="navbar">
        <div class="nav-content">
            <div class="nav-logo">WEIRD STEEL</div>
            <div class="nav-links">
                <?php foreach ($navItems as $key => $item): ?>
                    <a href="<?php echo $item['href']; ?>" 
                       <?php if (isset($item['highlight']) && $item['highlight']): ?>
                           style="color: #ff6b35;"
                       <?php endif; ?>>
                        <?php echo $item['text']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
<?php
}
?>
