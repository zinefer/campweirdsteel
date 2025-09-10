<?php
function renderHeader($title = "Weird Steel - Burning Man Art Camp", $additionalCss = []) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <?php foreach ($additionalCss as $css): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($css); ?>">
    <?php endforeach; ?>
</head>
<body>
    <!-- Animated particles -->
    <div class="particles" id="particles"></div>
<?php
}
?>
