<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['index'])) {
    $index = intval($_POST['index']);
    if (isset($_SESSION['compressed_images'][$index])) {
        unset($_SESSION['compressed_images'][$index]);
        $_SESSION['compressed_images'] = array_values($_SESSION['compressed_images']);
        echo 'Image deleted';
    } else {
        echo 'Image not found';
    }
} else {
    echo 'Invalid request';
}
?>
