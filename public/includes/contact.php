<?php
function renderContactSection($title = "Join the Forge", $content = null, $email = "contact@weirdsteel.camp") {
    if ($content === null) {
        $content = "Ready to get weird with steel? Whether you're an artist, a fire enthusiast, or just curious about what we do, 
            we welcome all who share our passion for creating the extraordinary. Come find us on the playa, 
            or reach out to learn more about joining our fiery family.";
    }
?>
    <!-- Contact Section -->
    <section id="contact" class="section">
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <p><?php echo htmlspecialchars($content); ?></p>
        <div style="text-align: center;">
            <a href="mailto:<?php echo htmlspecialchars($email); ?>" class="cta-button">Get In Touch</a>
        </div>
    </section>
<?php
}
?>
