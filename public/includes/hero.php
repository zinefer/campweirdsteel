<?php
function renderHeroSection($title = "WEIRD STEEL", $tagline = "Forging Fire • Creating Wonder • Burning Bright") {
?>
    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content">
            <h1 class="logo"><?php echo htmlspecialchars($title); ?></h1>
            <p class="tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <a href="#about" class="cta-button">Explore Our Art</a>
            <a href="forum/" class="cta-button">Member Login</a>
        </div>
        <div class="fire-effect"></div>
    </section>
<?php
}
?>
