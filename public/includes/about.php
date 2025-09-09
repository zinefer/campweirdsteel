<?php
function renderAboutSection($title = "Born in Fire & Steel", $content = null) {
    if ($content === null) {
        $content = "We are Weird Steel - a collective of fire artists, metal sculptors, and creative misfits who call the playa home. 
            Our camp is where sparks fly, metal bends to will, and impossible dreams take shape in flame and steel. 
            Every piece we create carries the spirit of radical self-expression and the raw power of transformation.";
    }
?>
    <!-- About Section -->
    <section id="about" class="section">
        <h2><?php echo htmlspecialchars($title); ?></h2>
        <p><?php echo htmlspecialchars($content); ?></p>
    </section>
<?php
}
?>
