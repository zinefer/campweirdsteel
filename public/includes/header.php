<?php
function renderHeader($title = "Weird Steel - Burning Man Art Camp") {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <!-- Animated particles -->
    <div class="particles" id="particles"></div>
<?php
}
?>
