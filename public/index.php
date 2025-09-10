<?php
// Include all component files
require_once '../includes/header.php';
require_once '../includes/navigation.php';
require_once '../includes/hero.php';
require_once '../includes/about.php';
require_once '../includes/gallery.php';
require_once '../includes/contact.php';
require_once '../includes/footer.php';

// Render the complete page
renderHeader("Weird Steel - Burning Man Art Camp");
renderNavigation('home');
renderHeroSection();
renderAboutSection();
renderGallerySection();
renderContactSection();
renderFooter();
?>