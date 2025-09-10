<?php
function renderGallerySection($title = "Our Creations", $description = null, $galleryItems = null) {
    if ($description === null) {
        $description = "From towering flame sculptures to intricate metalwork, each piece tells a story of passion, skill, and unbridled creativity.";
    }
    
    if ($galleryItems === null) {
        $galleryItems = [
            [
                "title" => "Guma",
                "image" => "assets/img/guma.jpg"
            ],
            [
                "title" => "The Cheese Palace", 
                "image" => "assets/img/cheese-palace.png"
            ],
            [
                "title" => "Rhino Redemption",
                "image" => "assets/img/rhino-redemption.webp"
            ]
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
                    <?php if (is_array($item)): ?>
                        <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                        <div class="gallery-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <?php else: ?>
                        <div class="placeholder-text"><?php echo $item; ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!--<div style="text-align: center; margin-top: 2rem;">
            <a href="forum/" class="cta-button">View Full Gallery</a>
        </div>-->
    </section>
<?php
}
?>
