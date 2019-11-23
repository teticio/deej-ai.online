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
    $art = $ThisFileInfo['comments']['picture'];
    if (!empty($art)) {
        print 'data:' . $art[0]['image_mime'] . ';charset=utf-8;base64,' . base64_encode($art[0]['data']);
    } else {
        print 'data:image/jpeg;charset=utf-8;base64,' . base64_encode(file_get_contents('record.jpg'));
    }
?>