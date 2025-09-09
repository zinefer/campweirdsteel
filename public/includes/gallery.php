<?php
function renderGallerySection($title = "Our Creations", $description = null, $galleryItems = null) {
    if ($description === null) {
        $description = "From towering flame sculptures to intricate metalwork, each piece tells a story of passion, skill, and unbridled creativity.";
    }
    
    if ($galleryItems === null) {
        $galleryItems = [
            "Fire Sculpture<br>Collection",
            "Steel Art<br>Installations", 
            "Interactive<br>Flame Pieces",
            "Collaborative<br>Works"
        ];
    }
?>
    <!-- Art Gallery Preview -->
    <section id="art" class="section">
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <p><?php echo htmlspecialchars($description); ?></p>
        
        <div class="gallery-preview">
            <?php foreach ($galleryItems as $item): ?>
                <div class="gallery-item">
                    <div class="placeholder-text"><?php echo $item; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="text-align: center; margin-top: 2rem;">
            <a href="forum/" class="cta-button">View Full Gallery</a>
        </div>
    </section>
<?php
}
?>
