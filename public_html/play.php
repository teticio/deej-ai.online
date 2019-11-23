<?php
    if (!isset($_GET['hello'])) {
        include('404.html');
        die();
    }

    $path = __DIR__ . '/../' .$_GET['file'];
    header('Content-Type: audio/mpeg');
    header('Cache-Control: no-cache');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . filesize($path));
    header('Accept-Ranges: bytes');
    readfile($path);
?>