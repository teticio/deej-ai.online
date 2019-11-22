<?php
    // make sure bandcamp server is running
    $port = ($_SERVER['HTTP_HOST'] != 'localhost')? 5124: 5126;
    $bandcamp_server = 'http://localhost:' . $port . '/bandcamp_server';
    $connection = @fsockopen('localhost', $port);
    if (is_resource($connection)) {
        fclose($connection);
    } else {
        $output = exec('(cd ..; ./start_bandcamp_server ' . $port . ' > /dev/null 2> /dev/null &)');
        sleep(10);
    }
    $curlopts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_POST => true,
    ];

    // directory to store active ids
    $ids_dir = '../bandcamp_ids';

    // remove old playlists on bandcamp server
    function removePlaylists($id) {
        global $bandcamp_server;
        global $curlopts;

        $postdata = ['remove_playlists_for_client' => $id];
        $payload = json_encode($postdata); 
        $ch = curl_init($bandcamp_server);
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

    // get search results from bandcamp server
    function searchTracks() {
        global $bandcamp_server;
        global $curlopts;

        $postdata = ['search_spotify' => $_POST['string']];
        $payload = json_encode($postdata); 
        $ch = curl_init($bandcamp_server);
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

    // get number of tracks from bandcamp server
    function numTracks() {
        global $bandcamp_server;
        global $curlopts;

        $postdata = ['num_tracks'];
        $payload = json_encode($postdata); 
        $ch = curl_init($bandcamp_server);
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

    // get next track in playlist from bandcamp server
    function nextTrack() {
        global $bandcamp_server;
        global $curlopts;

        $postdata = ['playlist_id' => $_POST['playlist']];
        $payload = json_encode($postdata); 
        $ch = curl_init($bandcamp_server);
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

    // create new playlist on bandcamp server
    function newPlaylist() {
        global $bandcamp_server;
        global $curlopts;
        global $ids_dir;

        // create file that gets deleted if user goes away
        $file = fopen(c .'/' . $_POST['id'], 'w');
        fclose($file);
        if (isset($_POST['url'])) {
            $postdata = [
                'client_id' => $_POST['id'],
                'spotify_url' => $_POST['url']
            ];
        } else {
             $postdata = [
                'client_id' => $_POST['id'],
                'bandcamp_url' => ''
            ];
        }
        $payload = json_encode($postdata); 
        $ch = curl_init($bandcamp_server);
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

    // garbage collection
    $dir = new DirectoryIterator($ids_dir);
    foreach ($dir as $fileinfo) {
        if (!$fileinfo->isDot() && filemtime($fileinfo->getPathname()) - time() > strtotime('1 day', 0)) {
            unlink($fileinfo->getPathname());
            removePlaylist($fileinfo->getFilename());
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
        }
    } else {
        // load page

?>
<!doctype html>
<html lang="en">

<head>
    <title>Bandcamp Radio</title>
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
                id = window.localStorage.id;
                playlist_id = window.localStorage.playlist_id;
                track_info = JSON.parse(window.localStorage.track_info);
                setTrack();
            } finally {
                if (!id || id == '') {
                    id = getUniqueID();
                    try { window.localStorage.id = id; } catch {}
                }
                if (!playlist_id || playlist_id == '' || !track_info || track_info.length == 0) {
                    newPlaylist();
                }
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
                $.post(window.location.href, 'action=next&playlist=' + playlist_id, function (data, status) {
                    track_info = JSON.parse(data);
                    try { window.localStorage.track_info = data; } catch {}
                    cb();
                });
            }
        }

        function setTrack(play = false) {
            if  (track_info[0].substring(0, 8) != '!DOCTYPE') {
                $('#album-art').on('load', function () {
                    $('#mp3').attr('src', track_info[0]);
                    if (play) {
                        $('#mp3')[0].play();
                    }
                    $('#player').css('visibility',  'visible');
                    $('#track').attr('href', track_info[2]);
                    $('#track').html(track_info[5]);
                    $('#artist').attr('href', track_info[2].substring(0, track_info[2].search('bandcamp.com')) + 'bandcamp.com');
                    $('#artist').html(track_info[3]);
                    $('#album-link').attr('href', track_info[2]);
                });
                $('#album-art').attr('src', track_info[1]);
            } else {
                nextTrack();
            }
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
                try { window.localStorage.playlist_id = playlist_id; } catch {}
                getNextTrack(playlist_id, function () {
                    setTrack(play);
                    if (cb) {
                        cb();
                    }
                });
            });
        }

        function spotifyToBandcamp() {
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

        .bandcamp-radio-heading, .bandcamp-radio-heading:hover {
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

        .bandcamp-radio {
            background-color: #333;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #000;
            box-shadow: -5px 5px 8px #111;
        }

        .bandcamp-radio-info {
            background-color: #222;
            box-shadow: inset -1px 1px 3px #111;
        }

        .bandcamp-radio-track {
            font-weight: bold;
        }

        .bandcamp-radio-artist {
            font-style: italic;
        }

        .fa-stack {
            font-size: 1em;
        }

        i {
            vertical-align: middle;
        }

        .bandcamp-radio-icon-foreground {
            cursor: pointer;
            color: #333;
        }

        .bandcamp-radio-icon-background {
            cursor: pointer;
            color: #eee;
            text-shadow: -5px 5px 8px #111;
        }

        .bandcamp-radio-icon-foreground:hover {
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
                    <h4 class="modal-title">Spotify to Bandcamp</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control" placeholder="Search..." onchange="searchTracks()"
                        id="search_input">
                    <label for="search_results">Search results</label>
                    <select class="form-control" style="overflow-x: scroll" id="search_results"
                        oninput="spotifyToBandcamp()">
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
        <h2><a href="/" class="bandcamp-radio-heading">Deej-A.I.</a></h2>
        <span class="bandcamp-radio">
            <div class="row align-items-center" id="player" style="visibility: hidden">
                <div class="col-md-3">
                    <a href="" target="_blank" id="album-link"><img src="" width="100%" id="album-art"></a>
                </div>
                <div class="col-md-9 text-center">
                    <div class="d-flex align-items-center justify-content-between">
                        <span>
                            <img src="bandcamp-logo.png" style="height: 35px; width: auto;">
                            <span style="font-size: 16px;">radio</span>
                        </span>
                        <span>
                            <span style="font-size: 16px;">Spotify</span>
                            <span class="fa-stack fa-2x" data-toggle="modal" data-target="#popupSpotify"
                                onclick="getNumTracks();">
                                <i class="fa fa-circle fa-stack-2x bandcamp-radio-icon-background" id="spotify"></i>
                                <i class="fa fa-spotify fa-stack-1x fa-inverse bandcamp-radio-icon-foreground"></i>
                            </span>
                        </span>
                    </div>
                    <br>
                    <div class="bandcamp-radio-info">
                        <div>
                            <div class="d-inline-block">
                                <h3 class="bandcamp-radio-track">
                                    <a href="" target="_blank" id="track"></a>
                                </h3>
                            </div>
                        </div>
                        <div>
                            <div class="d-inline-block">
                                <h4 class="bandcamp-radio-artist">
                                    <a href="" target="_blank" id="artist"></a>
                                </h4>
                            </div>
                        </div>
                    </div>
                    <br>
                    <div class="d-flex align-items-center">
                        <span class="fa-stack fa-2x" onclick="ejectTrack();">
                            <i class="fa fa-circle fa-stack-2x bandcamp-radio-icon-background" id="eject"></i>
                            <i class="fa fa-eject fa-stack-1x fa-inverse bandcamp-radio-icon-foreground"></i>
                        </span>
                        <span style="display:inline-block; width: 10px;"></span>
                        <audio controls controlsList="nodownload" style="width:100%" onended="nextTrack(true);"
                            id="mp3">
                            <source src="" type="audio/mp3">
                        </audio>
                        <span style="display:inline-block; width: 10px;"></span>
                        <span class="fa-stack fa-2x" onclick="nextTrack();">
                            <i class="fa fa-circle fa-stack-2x bandcamp-radio-icon-background" id="next"></i>
                            <i class="fa fa-forward fa-stack-1x fa-inverse bandcamp-radio-icon-foreground"></i>
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