<?php
    if (!isset($_GET['hello'])) {
        include('404.html');
        die();
    }

    require '../vendor/autoload.php';
    $getID3 = new getID3;
    $path = __DIR__ . '/../' .$_GET['file'];
    $ThisFileInfo = $getID3->analyze($path);
    getid3_lib::CopyTagsToComments($ThisFileInfo);
    echo $ThisFileInfo['tags']['id3v2']['title'][0];
?>