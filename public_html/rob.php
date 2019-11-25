<?php
    if (!isset($_GET['hello'])) {
        include('404.html');
        die();
    }

    require '../vendor/autoload.php';

    // make sure rob server is running
    $port = 5127;
    $rob_server = 'http://localhost:' . $port . '/rob_server';
    $connection = @fsockopen('localhost', $port);
    if (is_resource($connection)) {
        fclose($connection);
    } else {
        $output = exec('(cd ..; ./start_rob_server ' . $port . ' > /dev/null 2> /dev/null &)');
        sleep(10);
    }
    $curlopts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_POST => true,
    ];

    // directory to store active ids
    $ids_dir = __DIR__ . '/../rob_ids';

    // remove old playlists on rob server
    function removePlaylists($id) {
        global $rob_server;
        global $curlopts;

        $postdata = ['remove_playlists_for_client' => $id];
        $payload = json_encode($postdata); 
        $ch = curl_init($rob_server);
        curl_setopt_array($ch, $curlopts);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        print $result;
        curl_close($ch);
    }

    // get search results from rob server
    function searchTracks() {
        global $rob_server;
        global $curlopts;

        $postdata = ['search_spotify' => $_POST['string']];
        $payload = json_encode($postdata); 
        $ch = curl_init($rob_server);
        curl_setopt_array($ch, $curlopts);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        print $result;
        curl_close($ch);
    }

    // get number of tracks from rob server
    function numTracks() {
        global $rob_server;
        global $curlopts;

        $postdata = ['num_tracks'];
        $payload = json_encode($postdata); 
        $ch = curl_init($rob_server);
        curl_setopt_array($ch, $curlopts);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        print $result;
        curl_close($ch);
    }

    // get next track in playlist from rob server
    function nextTrack() {
        global $rob_server;
        global $curlopts;
        global $ids_dir;

        // touch file that gets deleted after a while
        touch($ids_dir .'/' . $_POST['id']);
        $postdata = ['playlist_id' => $_POST['playlist']];
        $payload = json_encode($postdata); 
        $ch = curl_init($rob_server);
        curl_setopt_array($ch, $curlopts);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        print $result;
        curl_close($ch);
    }

    // create new playlist on rob server
    function newPlaylist() {
        global $rob_server;
        global $curlopts;
        global $ids_dir;

        // touch file that gets deleted after a while
        touch($ids_dir .'/' . $_POST['id']);
        if (isset($_POST['url'])) {
            $postdata = [
                'client_id' => $_POST['id'],
                'spotify_url' => $_POST['url']
            ];
        } else {
             $postdata = [
                'client_id' => $_POST['id'],
                'mp3' => '',
            ];
        }
        $payload = json_encode($postdata); 
        $ch = curl_init($rob_server);
        curl_setopt_array($ch, $curlopts);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        $result = curl_exec($ch);
        print $result;
        curl_close($ch);
    }

    function getInfo() {
        $getID3 = new getID3;
        $path = __DIR__ . '/../' .$_POST['file'];
        $ThisFileInfo = $getID3->analyze($path);
        getid3_lib::CopyTagsToComments($ThisFileInfo);
        $art = $ThisFileInfo['comments']['picture'];
        if (!empty($art)) {
            $src = 'data:' . $art[0]['image_mime'] . ';charset=utf-8;base64,' . base64_encode($art[0]['data']);
        } else {
            $src =  'data:image/jpeg;charset=utf-8;base64,' . base64_encode(file_get_contents('record.jpg'));
        }
        $track = $ThisFileInfo['comments']['title'][0];
        $artist = $ThisFileInfo['comments']['artist'][0];
        if ($track == null) {
            $track = $ThisFileInfo['filename'];
        }
        if ($artist == null) {
            $artist = 'Unknown';
        }
        $info = ['<img src="' . $src . '" width="100%">', $track, $artist];
        print json_encode($info);
    }

    // garbage collection
    $dir = new DirectoryIterator($ids_dir);
    foreach ($dir as $fileinfo) {
        if (!$fileinfo->isDot() && time() - filemtime($fileinfo->getPathname()) > strtotime('1 day', 0)) {
            unlink($fileinfo->getPathname());
            removePlaylists($fileinfo->getFilename());
        }
    }

    if (isset($_POST['action'])) {
        // do stuff
        switch ($_POST['action']) {
            case 'bye':
                removePlaylists($_POST['id']);
                break;

            case 'search':
                searchTracks();
                break;

            case 'numtracks':
                numTracks();
                break;

            case 'next':
                nextTrack();
                break;

            case 'playlist':
                newPlaylist();
                break;

            case 'info':
                getInfo();
                break;
        }
    } else {
        // load page

?>
<!doctype html>
<html lang="en">

<head>
    <title>Rob Radio</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap-->
    <link href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"
        integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link href="https://stackpath.bootstrapcdn.com/bootswatch/4.3.1/darkly/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-w+8Gqjk9Cuo6XH9HKHG5t5I1VR4YBNdPt/29vwgfZR485eoEJZ8rJRbm3TR32P6k" crossorigin="anonymous">

    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"
        integrity="sha384-xrRywqdh3PHs8keKZN+8zzc5TX0GRTLCcmivcbNJWm2rs5C8PRhcEn3czEjhAO9o"
        crossorigin="anonymous"></script>

    <script>
        const getUniqueID = () => {
            const s4 = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
            return s4() + s4() + '-' + s4();
        };
        var id = null;
        var track_info = null;
        var playlist_id = null;

        $(document).ready(function () {
            try {
                id = window.localStorage.rob_id;
                playlist_id = window.localStorage.rob_playlist_id;
                track_info = window.localStorage.rob_track_info;
                setTrack();
            } catch {}
            if (!id || id == '') {
                id = getUniqueID();
                try { window.localStorage.rob_id = id; } catch {}
            }
            if (!playlist_id || playlist_id == '' || !track_info || track_info.length == 0) {
                newPlaylist();
            }
        });

        function searchTracks() {
            if (search_input != '') {
                $.post(window.location.href, jQuery.param({
                    'action': 'search',
                    'string':  $('#search_input').val()
                }), function (data, status) {
                    results = JSON.parse(data);
                    $('#search_results').find('option').not(':first').remove();
                    results.forEach(function (item) {
                        $('#search_results').append(new Option(item['artist'] + ' - ' + item['track'], item['preview_url']));
                    });
                });
            }
        }

        function getNumTracks() {
            $.post(window.location.href, 'action=numtracks', function (data, status) {
                $('#num_tracks').html(data + ' tracks...');
            });
        }

        function getNextTrack(playlist_id, cb) {
            if (playlist_id != '' && playlist_id != null) {
                $.post(window.location.href, jQuery.param({
                    'action': 'next',
                    'id': id,
                    'playlist':  playlist_id,
                }), function (data, status) {
                    track_info = data;
                    try { window.localStorage.rob_track_info = data; } catch {}
                    cb();
                });
            }
        }

        function setTrack(play = false) {
            $.post(window.location.href, 'hello&action=info&file=' + encodeURIComponent(track_info), function (data, status) {
                info = JSON.parse(data);
                $('#album-art').html(info[0]);
                $('#track').html(info[1]);
                $('#artist').html(info[2]);
                $('#player').css('visibility',  'visible');
            });
            $('#mp3').attr('autoplay', play);
            $('#mp3').attr('src', 'play.php?hello&file=' + encodeURIComponent(track_info));
        }

        function newPlaylist(play = false, cb = null, url = null) {
            if (url) {
                body = jQuery.param({
                    'action' : 'playlist',
                    'id': id,
                    'url': url
                });
            } else {
                body = jQuery.param({
                    'action' : 'playlist',
                    'id': id
                });
            }
            $.post(window.location.href, body, function (data, status) {
                playlist_id = data;
                try { window.localStorage.rob_playlist_id = playlist_id; } catch {}
                getNextTrack(playlist_id, function () {
                    setTrack(play);
                    if (cb) {
                        cb();
                    }
                });
            });
        }

        function spotifyToRob() {
            playing = !$('#mp3')[0].paused
            $('#status').html('Analyzing');
            $('#num_tracks').show();
            spotify_url = $('#search_results :selected').val();
            if (spotify_url == '') {
                return;
            }
            newPlaylist(playing, function () {
                $('#num_tracks').hide();
                $('#status').html('&nbsp;');
            }, spotify_url)
        }

        function nextTrack(play = false) {
            playing = !$('#mp3')[0].paused
            $('#next').css('textShadow', 'none');
            getNextTrack(playlist_id, function () {
                setTrack(play || playing);
                $('#next').css('textShadow', '-5px 5px 8px #111');
            });
        }

        function ejectTrack() {
            playing = !$('#mp3')[0].paused
            $('#eject').css('textShadow', 'none');
            newPlaylist(playing, function () {
                $('#eject').css('textShadow', '-5px 5px 8px #111');
            });
        }    
    </script>

    <style>
        a,
        a:hover {
            text-decoration: none;
        }

        audio:focus {
            outline: none;
        }

        audio {
            width: 200px;
            height: 25px;
            box-shadow: -5px 5px 8px #111;
            border-radius: 90px;
        }

        audio::-webkit-media-controls-current-time-display,
        audio::-webkit-media-controls-time-remaining-display {
            display: none;
        }

        html,
        body {
            background-image: url("records.jpg");
            background-position: center top;
            height: 100%;
            font-family: 'Helvetica', 'Arial', sans-serif;
            position: relative;
            z-index: -10;
        }

        .rob-radio-heading, .rob-radio-heading:hover {
            text-decoration: none;
            color: inherit;
            position: absolute;
            top: 8px;
            left: 16px;
            z-index: -5;
        }

        .container {
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .rob-radio {
            background-color: #333;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #000;
            box-shadow: -5px 5px 8px #111;
        }

        .rob-radio-info {
            background-color: #222;
            box-shadow: inset -1px 1px 3px #111;
        }

        .rob-radio-track {
            font-weight: bold;
        }

        .rob-radio-artist {
            font-style: italic;
        }

        .fa-stack {
            font-size: 1em;
        }

        i {
            vertical-align: middle;
        }

        .rob-radio-icon-foreground {
            cursor: pointer;
            color: #333;
        }

        .rob-radio-icon-background {
            cursor: pointer;
            color: #eee;
            text-shadow: -5px 5px 8px #111;
        }

        .rob-radio-icon-foreground:hover {
            color: #000;
        }
    </style>
</head>

<body>
    <!-- Modal -->
    <div id="popupSpotify" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header d-flex justify-content-between">
                    <h4 class="modal-title">Spotify to Rob</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control" placeholder="Search..." onchange="searchTracks()"
                        id="search_input">
                    <label for="search_results">Search results</label>
                    <select class="form-control" style="overflow-x: scroll" id="search_results"
                        oninput="spotifyToRob()">
                        <option value="" selected>Select...</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <span id="status">&nbsp;</span><span id="num_tracks" style="display: none;"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <h2><a href="/" class="rob-radio-heading">Deej-A.I.</a></h2>
        <span class="rob-radio">
            <div class="row align-items-center" id="player" style="visibility: hidden">
                <div class="col-md-3">
                    <span id="album-art"></span>
                </div>
                <div class="col-md-9 text-center">
                    <div class="d-flex align-items-center justify-content-between">
                        <span style="font-size: 16px;">Rob radio</span>
                        <span>
                            <span style="font-size: 16px;">Spotify</span>
                            <span class="fa-stack fa-2x" data-toggle="modal" data-target="#popupSpotify"
                                onclick="getNumTracks();">
                                <i class="fa fa-circle fa-stack-2x rob-radio-icon-background" id="spotify"></i>
                                <i class="fa fa-spotify fa-stack-1x fa-inverse rob-radio-icon-foreground"></i>
                            </span>
                        </span>
                    </div>
                    <br>
                    <div class="rob-radio-info">
                        <div>
                            <div class="d-inline-block">
                                <h3 class="rob-radio-track">
                                    <span id="track"></span>
                                </h3>
                            </div>
                        </div>
                        <div>
                            <div class="d-inline-block">
                                <h4 class="rob-radio-artist">
                                    <span id="artist"></span>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <br>
                    <div class="d-flex align-items-center">
                        <span class="fa-stack fa-2x" onclick="ejectTrack();">
                            <i class="fa fa-circle fa-stack-2x rob-radio-icon-background" id="eject"></i>
                            <i class="fa fa-eject fa-stack-1x fa-inverse rob-radio-icon-foreground"></i>
                        </span>
                        <span style="display: inline-block; width: 10px;"></span>
                        <audio controls preload="auto" style="width: 100%" onended="nextTrack(true);"
                            id="mp3">
                            <source src="" type="audio/mp3">
                        </audio>
                        <audio controls autoplay loop preload="auto" style="display: none;" id="hack">
                            <source src="silence.mp3" type="audio/mp3">
                        </audio>
                        <span style="display: inline-block; width: 10px;"></span>
                        <span class="fa-stack fa-2x" onclick="nextTrack();">
                            <i class="fa fa-circle fa-stack-2x rob-radio-icon-background" id="next"></i>
                            <i class="fa fa-forward fa-stack-1x fa-inverse rob-radio-icon-foreground"></i>
                        </span>
                    </div>
                </div>
            </div>
        </span>
    </div>
</body>

</html>
<?php
    }
?>